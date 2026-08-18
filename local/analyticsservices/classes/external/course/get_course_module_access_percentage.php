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
 * External function to get course module access percentage.
 *
 * @package   local_analyticsservices
 * @copyright 2026, Arya Kusuma <muhammadaryakusuma@gmail.com>
 * @copyright 2026, Safiyyah Yahya <safiyyahyahya163@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_analyticsservices\external\course;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;
use local_analyticsservices\helper;

/**
 * Class get_course_module_access_percentage.
 */
class get_course_module_access_percentage extends external_api {

    /**
     * Define the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $courseid Course ID.
     * @return array
     */
    public static function execute($courseid) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('report/log:view', $context);

        // Get course data.
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);

        // Get students enrolled in this course.
        $students      = helper::get_students_in_course($courseid);
        $studentids    = array_map(fn($s) => (int)$s->id, $students);
        $totalstudents = count($studentids);

        if ($totalstudents === 0) {
            return [
                'course' => [
                    'id'        => $courseid,
                    'name'      => $course->fullname,
                    'shortname' => $course->shortname,
                    'modules'   => [],
                ],
            ];
        }

        $sql = "SELECT cm.id AS cmid, cm.instance, m.name AS modname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.course = :courseid
                   AND cm.deletioninprogress = 0";

        $modules = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        if (empty($modules)) {
            return [
                'course' => [
                    'id'        => $courseid,
                    'name'      => $course->fullname,
                    'shortname' => $course->shortname,
                    'modules'   => [],
                ],
            ];
        }

        $cmids = array_keys($modules);
        list($cmsql, $cmparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cmid');
        list($usersql, $userparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'userid');

        $sql = "SELECT contextinstanceid AS cmid, COUNT(DISTINCT userid) AS viewed_count
                  FROM {logstore_standard_log}
                 WHERE courseid = :courseid
                   AND contextinstanceid $cmsql
                   AND userid $usersql
                   AND action = 'viewed'
                 GROUP BY cmid";

        $mergedparams = array_merge(['courseid' => $courseid], $cmparams, $userparams);

        $logdata = $DB->get_records_sql($sql, $mergedparams);

        $resultmodules = [];
        foreach ($modules as $mod) {
            $viewedcount = isset($logdata[$mod->cmid]) ? $logdata[$mod->cmid]->viewed_count : 0;
            $percent     = round(($viewedcount / $totalstudents) * 100, 2);

            $resultmodules[] = [
                'cmid'              => $mod->cmid,
                'modname'           => $mod->modname,
                'total_viewed'      => $viewedcount,
                'percentage_viewed' => $percent,
            ];
        }

        return [
            'course' => [
                'id'        => $courseid,
                'name'      => $course->fullname,
                'shortname' => $course->shortname,
                'modules'   => $resultmodules,
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
            'course' => new external_single_structure([
                'id'        => new external_value(PARAM_INT, 'Course ID'),
                'name'      => new external_value(PARAM_TEXT, 'Nama course.'),
                'shortname' => new external_value(PARAM_TEXT, 'Shortname course.'),
                'modules'   => new external_multiple_structure(
                    new external_single_structure([
                        'cmid'              => new external_value(PARAM_INT, 'Course module ID'),
                        'modname'           => new external_value(PARAM_TEXT, 'Nama module (type).'),
                        'total_viewed'      => new external_value(PARAM_INT, 'Total mahasiswa yang membuka module ini.'),
                        'percentage_viewed' => new external_value(PARAM_FLOAT, 'Persentase mahasiswa membuka module.'),
                    ])
                ),
            ]),
        ]);
    }
}
