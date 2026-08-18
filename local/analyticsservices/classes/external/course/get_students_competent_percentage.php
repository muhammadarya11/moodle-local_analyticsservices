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
 * External function to get competent students percentage in a course.
 *
 * @package   local_analyticsservices
 * @copyright 2026, Arya Kusuma <muhammadaryakusuma@gmail.com>
 * @copyright 2026, Safiyyah Yahya <safiyyahyahya163@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_analyticsservices\external\course;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;
use local_analyticsservices\helper;

/**
 * Class get_students_competent_percentage.
 */
class get_students_competent_percentage extends external_api {

    /**
     * Define the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid'               => new external_value(PARAM_INT, 'Course ID'),
            'grade_threshold'        => new external_value(
                PARAM_FLOAT,
                'Minimum average grade (0-100) to be considered competent.',
                VALUE_DEFAULT,
                50.0
            ),
            'competent_activity_rate' => new external_value(
                PARAM_FLOAT,
                'Minimum percentage of graded activities completed to be competent (0-100).',
                VALUE_DEFAULT,
                80.0
            ),
            'inactive_activity_rate'  => new external_value(
                PARAM_FLOAT,
                'Maximum percentage of graded activities completed to be considered a ghost student (0-100).',
                VALUE_DEFAULT,
                20.0
            ),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $courseid Course ID.
     * @param float $gradethreshold Grade threshold.
     * @param float $competentactivityrate Competent activity rate.
     * @param float $inactiveactivityrate Inactive activity rate.
     * @return array
     */
    public static function execute($courseid, $gradethreshold, $competentactivityrate, $inactiveactivityrate) {
        global $DB;

        // Validate parameters and context course.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'                => $courseid,
            'grade_threshold'         => $gradethreshold,
            'competent_activity_rate' => $competentactivityrate,
            'inactive_activity_rate'  => $inactiveactivityrate,
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/grade:viewall', $context);

        // Get course data.
        $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, fullname, shortname', MUST_EXIST);

        // Get student data.
        $students = helper::get_students_in_course($courseid);
        if (empty($students)) {
            return [
                'course' => [
                    'id'               => $course->id,
                    'fullname'         => $course->fullname,
                    'shortname'        => $course->shortname,
                    'students'         => [
                        'total'       => 0,
                        'competent'   => 0,
                        'incompetent' => 0,
                        'inactive'    => 0,
                    ],
                    'total_activities' => 0,
                ],
            ];
        }

        $totalstudents = count($students);

        // Get graded activities.
        $gradedmodules = $DB->get_records_sql(
            "SELECT DISTINCT
                gi.id AS gradeitemid,
                gi.itemname AS name,
                gi.itemmodule AS modname,
                gi.grademax,
                cm.instance AS iteminstance,
                cm.id AS cmid
              FROM {grade_items} gi
              JOIN {modules} m ON m.name = gi.itemmodule
              JOIN {course_modules} cm ON cm.module = m.id
                   AND cm.instance = gi.iteminstance
                   AND cm.course = gi.courseid
                   AND cm.visible = 1
             WHERE gi.courseid = :courseid
               AND cm.deletioninprogress = 0
               AND gi.itemtype = 'mod'
               AND gi.itemmodule IS NOT NULL
               AND gi.gradetype != 0
               AND gi.grademax > 0
               AND (gi.itemmodule != 'assign'
                    OR (SELECT grade FROM {assign} WHERE id = gi.iteminstance) != 0)",
            ['courseid' => $courseid]
        );
        $totalgradedactivities = count($gradedmodules);

        // If no graded activities, consider all students competent.
        if (empty($gradedmodules)) {
            return [
                'course' => [
                    'id'               => $course->id,
                    'fullname'         => $course->fullname,
                    'shortname'        => $course->shortname,
                    'students'         => [
                        'total'       => $totalstudents,
                        'competent'   => $totalstudents,
                        'incompetent' => 0,
                        'inactive'    => 0,
                    ],
                    'total_activities' => 0,
                ],
            ];
        }

        // Get all grades for graded activities.
        list($gradeitemsql, $gradeitemparams) = $DB->get_in_or_equal(
            array_column($gradedmodules, 'gradeitemid'),
            SQL_PARAMS_NAMED,
            'giid'
        );

        $gradesall = $DB->get_records_sql(
            "SELECT id, userid, itemid, finalgrade
               FROM {grade_grades}
              WHERE itemid $gradeitemsql",
            $gradeitemparams
        );

        $gradesbyuser = [];
        foreach ($gradesall as $g) {
            $gradesbyuser[$g->userid][$g->itemid] = $g->finalgrade;
        }

        $participatedbymodule = [];
        foreach ($gradedmodules as $module) {
            $participatedbymodule[$module->gradeitemid] = helper::get_participated_users($module, $gradesbyuser);
        }

        // Categorize each student.
        list($competentcount, $incompetentcount, $inactivecount) = helper::calculate_student_competency_stats(
            $students,
            $gradedmodules,
            $gradesbyuser,
            $participatedbymodule,
            $params
        );

        return [
            'course' => [
                'id'               => $course->id,
                'fullname'         => $course->fullname,
                'shortname'        => $course->shortname,
                'students'         => [
                    'total'       => $totalstudents,
                    'competent'   => $competentcount,
                    'incompetent' => $incompetentcount,
                    'inactive'    => $inactivecount,
                ],
                'total_activities' => $totalgradedactivities,
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
                'id'               => new external_value(PARAM_INT, 'Course ID'),
                'fullname'         => new external_value(PARAM_TEXT, 'Full name'),
                'shortname'        => new external_value(PARAM_TEXT, 'Short name'),
                'students'         => new external_single_structure([
                    'total'       => new external_value(PARAM_INT, 'Total number of students.'),
                    'competent'   => new external_value(PARAM_INT, 'Number of competent students.'),
                    'incompetent' => new external_value(PARAM_INT, 'Number of incompetent students.'),
                    'inactive'    => new external_value(PARAM_INT, 'Number of ghost/inactive students.'),
                ]),
                'total_activities' => new external_value(PARAM_INT, 'Total number of graded activities.'),
            ]),
        ]);
    }
}
