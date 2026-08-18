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
 * External function to get uncompetent activities in a course.
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
 * Class get_uncompetent_activities.
 */
class get_uncompetent_activities extends external_api {

    /**
     * Define the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid'  => new external_value(PARAM_INT, 'Course ID'),
            'threshold' => new external_value(PARAM_FLOAT, 'Nilai batas kompetensi.', VALUE_DEFAULT, 80.0),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $courseid Course ID.
     * @param float $threshold Grade threshold.
     * @return array
     */
    public static function execute($courseid, $threshold = 80.0) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'  => $courseid,
            'threshold' => $threshold,
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/grade:viewall', $context);

        $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, fullname, shortname', MUST_EXIST);

        $students = helper::get_students_in_course($courseid);
        if (empty($students)) {
            return [
                'course' => [
                    'id'         => $course->id,
                    'fullname'   => $course->fullname,
                    'shortname'  => $course->shortname,
                    'activities' => [],
                ],
            ];
        }

        // List modules that have grades.
        $modules = $DB->get_records_sql(
            "SELECT DISTINCT
                gi.id AS gradeitemid,
                gi.itemname AS name,
                gi.itemmodule AS modname,
                cm.id AS cmid
              FROM {grade_items} gi
              JOIN {modules} m ON m.name = gi.itemmodule
              JOIN {course_modules} cm ON cm.module = m.id
                   AND cm.instance = gi.iteminstance
                   AND cm.course = gi.courseid
                   AND cm.visible = 1
                   AND cm.deletioninprogress = 0
             WHERE gi.courseid = :courseid
               AND gi.itemtype = 'mod'
               AND gi.itemmodule IS NOT NULL
               AND gi.gradetype != 0
               AND gi.grademax > 0
               AND (gi.itemmodule != 'assign'
                    OR (SELECT grade FROM {assign} WHERE id = gi.iteminstance) != 0)",
            ['courseid' => $courseid]
        );

        if (empty($modules)) {
            return [
                'course' => [
                    'id'         => $course->id,
                    'fullname'   => $course->fullname,
                    'shortname'  => $course->shortname,
                    'activities' => [],
                ],
            ];
        }

        list($gradeitemsql, $gradeitemparams) = $DB->get_in_or_equal(
            array_column($modules, 'gradeitemid'),
            SQL_PARAMS_NAMED,
            'giid'
        );

        $gradesall = $DB->get_records_sql(
            "SELECT id, userid, itemid, finalgrade
               FROM {grade_grades}
              WHERE itemid $gradeitemsql",
            $gradeitemparams
        );

        $studentids    = array_keys($students);
        $totalstudents = count($studentids);

        $gradesbyitem = [];
        foreach ($gradesall as $g) {
            $gradesbyitem[$g->itemid][$g->userid] = $g->finalgrade;
        }

        $result = [];

        foreach ($modules as $m) {
            $itemid = $m->gradeitemid;

            $uncompetent = 0;
            foreach ($studentids as $sid) {
                if (
                    !isset($gradesbyitem[$itemid][$sid]) ||
                    $gradesbyitem[$itemid][$sid] === null ||
                    $gradesbyitem[$itemid][$sid] < $threshold
                ) {
                    $uncompetent++;
                }
            }

            $percentuncompetent = $totalstudents > 0
                ? round(($uncompetent / $totalstudents) * 100, 2)
                : 0.0;

            if ($percentuncompetent == 100.0) {
                $result[] = [
                    'cmid'                => $m->cmid,
                    'modname'             => $m->modname,
                    'itemname'            => $m->name ?? '',
                    'percent_uncompetent' => $percentuncompetent,
                ];
            }
        }

        return [
            'course' => [
                'id'         => $course->id,
                'fullname'   => $course->fullname,
                'shortname'  => $course->shortname,
                'activities' => $result,
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
                'fullname'   => new external_value(PARAM_TEXT, 'Course full name'),
                'shortname'  => new external_value(PARAM_TEXT, 'Course short name'),
                'activities' => new external_multiple_structure(
                    new external_single_structure([
                        'cmid'                => new external_value(PARAM_INT, 'Course module ID'),
                        'modname'             => new external_value(PARAM_TEXT, 'Nama modul.'),
                        'itemname'            => new external_value(PARAM_TEXT, 'Nama aktivitas.'),
                        'percent_uncompetent' => new external_value(PARAM_FLOAT, 'Persentase belum kompeten.'),
                    ])
                ),
            ]),
        ]);
    }
}
