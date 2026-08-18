<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * External function to get course modules info by section.
 *
 * @package   local_analyticsservices
 * @copyright 2026, Arya Kusuma <muhammadaryakusuma@gmail.com>
 * @copyright 2026, Safiyyah Yahya <safiyyahyahya163@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_analyticsservices\external\section;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;
use local_analyticsservices\helper;

/**
 * Class get_course_modules_info_by_section.
 */
class get_course_modules_info_by_section extends external_api {

    /**
     * Define the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'sectionid' => new external_value(PARAM_INT, 'Section ID'),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $sectionid Section ID.
     * @return array
     */
    public static function execute($sectionid) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'sectionid' => $sectionid,
        ]);

        // Validate course context.
        $section = $DB->get_record('course_sections', ['id' => $params['sectionid']], 'id, course, name', MUST_EXIST);
        $context = context_course::instance($section->course);
        self::validate_context($context);
        require_capability('report/log:view', $context);

        // Get students enrolled in this course.
        $students = helper::get_students_in_course($section->course);
        $studentids = array_keys($students);
        $totalstudents = count($studentids);
        if (empty($students)) {
            return [
                'section' => [
                    'id'       => $section->id,
                    'name'     => $section->name,
                    'courseid' => $section->course,
                    'modules'  => [],
                ],
            ];
        }

        // Get active modules in this section.
        $modules = $DB->get_records_select(
            'course_modules',
            'course = ? AND section = ? AND visible = 1 AND deletioninprogress = 0',
            [$section->course, $sectionid],
            null,
            'id, course, section'
        );

        if (empty($modules)) {
            return [
                'section' => [
                    'id'       => $section->id,
                    'name'     => $section->name,
                    'courseid' => $section->course,
                    'modules'  => [],
                ],
            ];
        }

        $cmids = array_keys($modules);
        list($cmsql, $cmparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cmid');
        list($usersql, $userparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'uid');

        $sql = "SELECT l.contextinstanceid AS cmid,
                       COUNT(l.id) AS total_viewed,
                       COUNT(DISTINCT l.userid) AS total_users
                  FROM {logstore_standard_log} l
                 WHERE l.courseid = :courseid
                   AND l.contextinstanceid $cmsql
                   AND l.userid $usersql
                   AND l.action = 'viewed'
                 GROUP BY l.contextinstanceid";

        $mergedparams = array_merge(['courseid' => $section->course], $cmparams, $userparams);
        $logdata = $DB->get_records_sql($sql, $mergedparams);

        $result = [];
        foreach ($modules as $cm) {
            $log = $logdata[$cm->id] ?? null;
            $result[] = [
                'cmid'         => $cm->id,
                'total_viewed' => $log->total_viewed ?? 0,
                'users_viewed' => $log->total_users ?? 0,
                'total_users'  => $totalstudents,
            ];
        }

        return [
            'section' => [
                'id'       => $section->id,
                'name'     => $section->name,
                'courseid' => $section->course,
                'modules'  => $result,
            ],
        ];
    }

    /**
     * Define the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'section' => new external_single_structure([
                'id'       => new external_value(PARAM_INT, 'Section ID'),
                'name'     => new external_value(PARAM_TEXT, 'Section name'),
                'courseid' => new external_value(PARAM_INT, 'Course ID'),
                'modules'  => new external_multiple_structure(
                    new external_single_structure([
                        'cmid'         => new external_value(PARAM_INT, 'Course module ID'),
                        'total_viewed' => new external_value(PARAM_INT, 'Jumlah view pada modul ini.'),
                        'users_viewed' => new external_value(PARAM_INT, 'Jumlah mahasiswa yang membuka modul ini.'),
                        'total_users'  => new external_value(PARAM_INT, 'Jumlah mahasiswa yang enroll di course ini.'),
                    ])
                ),
            ]),
        ]);
    }
}
