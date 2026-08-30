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
 * External function to get graded course activities.
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
 * Class get_graded_course_activities.
 */
class get_graded_course_activities extends external_api {

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
     * Get data of activities that have been worked on and graded.
     *
     * @param int $courseid Course ID.
     * @return array
     */
    public static function execute($courseid) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
        'courseid' => $courseid,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('moodle/course:viewhiddencourses', $context);

        $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, fullname, shortname', MUST_EXIST);
        $students      = helper::get_students_in_course($params['courseid']);

        if (empty($students)) {
            return [
                'course' => [
                    'id'         => $params['courseid'],
                    'name'       => $course->fullname,
                    'shortname'  => $course->shortname,
                    'activities' => [],
                ],
            ];
        }

        // Optimasi: Memecahkan SQL raksasa menjadi 3 SQL sederhana.
        // Ambil struktur activity saja.
        $sql = "SELECT cm.id AS cmid,
                    m.name AS modname,
                    cm.instance AS iteminstance,
                    COALESCE(gi.id, 0) AS gradeitemid,
                    gi.itemname,
                    gi.gradetype
                FROM {course_modules} cm
                JOIN {modules} m ON cm.module = m.id
                LEFT JOIN {grade_items} gi ON gi.itemmodule = m.name
                    AND gi.iteminstance = cm.instance
                    AND gi.courseid = cm.course
                WHERE cm.course = :courseid
                  AND cm.deletioninprogress = 0
                  AND m.name IN ('assign', 'quiz', 'forum', 'workshop', 'lesson', 'scorm')";

        $records = $DB->get_records_sql($sql, ['courseid' => $params['courseid']]);

        $gradeitemids = [];
        foreach ($records as $r) {
            $r->gradedcount = 0; // Default awal.
            if ($r->gradeitemid) {
                $gradeitemids[] = $r->gradeitemid;
            }
        }

        $gradesbyuser = [];
        $studentids = array_keys($students);

        if (!empty($gradeitemids) && !empty($studentids)) {
            list($insqlgi, $paramsgi) = $DB->get_in_or_equal($gradeitemids, SQL_PARAMS_NAMED, 'giid0');
            list($insqlu, $paramsu)  = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'uid0');
            $sqlparams = array_merge($paramsgi, $paramsu);

            // Ambil nilai hanya untuk mahasiswa aktif dan activity yang terdaftar.
            $sqlgrades = "SELECT id, itemid, userid, COALESCE(finalgrade, rawgrade) AS finalgrade
                           FROM {grade_grades}
                           WHERE itemid $insqlgi AND userid $insqlu AND (finalgrade IS NOT NULL OR rawgrade IS NOT NULL)";

            $gradesall = $DB->get_records_sql($sqlgrades, $sqlparams);

            foreach ($gradesall as $g) {
                $gradesbyuser[$g->userid][$g->itemid] = $g->finalgrade;
            }

            // Ambil jumlah ter-grading per activity.
            $sqlcount = "SELECT itemid, COUNT(DISTINCT userid) AS gradedcount
                          FROM {grade_grades}
                          WHERE itemid $insqlgi AND userid $insqlu AND (finalgrade IS NOT NULL OR rawgrade IS NOT NULL)
                          GROUP BY itemid";
            $counts = $DB->get_records_sql($sqlcount, $sqlparams);

            foreach ($records as $r) {
                if ($r->gradeitemid && isset($counts[$r->gradeitemid])) {
                    $r->gradedcount = $counts[$r->gradeitemid]->gradedcount;
                }
            }
        }

        $results = helper::format_graded_activities_results(
            $params['courseid'],
            $records,
            $gradesbyuser,
            $students
        );

        return [
        'course' => [
            'id'         => $params['courseid'],
            'name'       => $course->fullname,
            'shortname'  => $course->shortname,
            'activities' => $results,
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
                'id'         => new external_value(PARAM_INT, 'Course ID'),
                'name'       => new external_value(PARAM_TEXT, 'Nama course.'),
                'shortname'  => new external_value(PARAM_TEXT, 'Shortname course.'),
                'activities' => new external_multiple_structure(
                    new external_single_structure([
                        'id'                 => new external_value(PARAM_INT, 'Activity instance ID'),
                        'name'               => new external_value(PARAM_TEXT, 'Activity name'),
                        'module'             => new external_value(PARAM_TEXT, 'Module name'),
                        'total_students'     => new external_value(PARAM_INT, 'Total number of students in course.'),
                        'students_submitted' => new external_value(PARAM_INT, 'Number of students who have submitted.'),
                        'students_graded'    => new external_value(PARAM_INT, 'Number of students who have been graded.'),
                        'has_grading'        => new external_value(PARAM_BOOL, 'Whether the activity has grading.'),
                    ]),
                    'List of graded activities in the course.'
                ),
            ]),
        ]);
    }
}