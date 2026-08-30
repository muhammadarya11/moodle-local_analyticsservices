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
 * External function to get inactive students.
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
use core_external\external_multiple_structure;
use context_course;
use local_analyticsservices\helper;

/**
 * Class get_inactive_students.
 */
class get_inactive_students extends external_api {

    /**
     * Define the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid'               => new external_value(PARAM_INT, 'Course ID'),
            'inactive_activity_rate' => new external_value(
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
     * @param float $inactiveactivityrate Inactive activity rate.
     * @return array
     */
    public static function execute($courseid, $inactiveactivityrate) {
        global $DB;

        // Validate parameters and context course.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'               => $courseid,
            'inactive_activity_rate' => $inactiveactivityrate,
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/grade:viewall', $context);

        // Get course data.
        $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, fullname, shortname', MUST_EXIST);

        $students = helper::get_students_in_course($courseid);

        if (empty($students)) {
            return ['course' => [
                'id'        => $course->id,
                'fullname'  => $course->fullname,
                'shortname' => $course->shortname,
                'students'  => [],
            ]];
        }

        // Get student last access separately.
        $studentids = array_keys($students);
        list($insql, $inparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'uid');
        $inparams['courseid']   = $courseid;
        $lastaccesses = $DB->get_records_sql(
            "SELECT userid, timeaccess FROM {user_lastaccess} WHERE courseid = :courseid AND userid $insql",
            $inparams
        );

        // Get graded activities.
        $gradedmodules = $DB->get_records_sql(
            "SELECT DISTINCT
                gi.id AS gradeitemid,
                gi.itemmodule AS modname,
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

        if (empty($gradedmodules)) {
            return ['course' => [
                'id'        => $course->id,
                'fullname'  => $course->fullname,
                'shortname' => $course->shortname,
                'students'  => [],
            ]];
        }

        // Get all grades for graded activities.
        list($gradeitemsql, $gradeitemparams) = $DB->get_in_or_equal(
            array_column($gradedmodules, 'gradeitemid'),
            SQL_PARAMS_NAMED,
            'giid'
        );

        $gradesall = $DB->get_records_sql(
            "SELECT id, userid, itemid, COALESCE(finalgrade, rawgrade) AS finalgrade
               FROM {grade_grades}
              WHERE itemid $gradeitemsql AND (finalgrade IS NOT NULL OR rawgrade IS NOT NULL)",
            $gradeitemparams
        );

        $gradesbyuser = [];
        foreach ($gradesall as $g) {
            $gradesbyuser[$g->userid][$g->itemid] = $g->finalgrade;
        }

        $participatedbymodule = helper::get_all_participated_users_in_course($courseid, $gradedmodules, $gradesbyuser);

        // Filter inactive/ghost students.
        $inactivestudents = [];

        foreach ($students as $student) {
            $uid = $student->id;

            $gradedparticipated = 0;
            foreach ($gradedmodules as $module) {
                if (!empty($participatedbymodule[$module->gradeitemid][$uid])) {
                    $gradedparticipated++;
                }
            }

            $participationrate = ($gradedparticipated / $totalgradedactivities) * 100;

            if ($participationrate <= $params['inactive_activity_rate']) {
                $inactivestudents[] = [
                    'id'                     => $student->id,
                    'firstname'              => $student->firstname,
                    'lastname'               => $student->lastname,
                    'email'                  => $student->email,
                    'lastaccess'             => $lastaccesses[$uid]->timeaccess ?? 0,
                    'participatedactivities' => $gradedparticipated,
                    'totalactivities'        => $totalgradedactivities,
                    'participationrate'      => round($participationrate, 2),
                ];
            }
        }

        return [
            'course' => [
                'id'        => $course->id,
                'fullname'  => $course->fullname,
                'shortname' => $course->shortname,
                'students'  => $inactivestudents,
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
                'fullname'  => new external_value(PARAM_TEXT, 'Course full name'),
                'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
                'students'  => new external_multiple_structure(
                    new external_single_structure([
                        'id'                     => new external_value(PARAM_INT, 'Student ID'),
                        'firstname'              => new external_value(PARAM_TEXT, 'Student first name'),
                        'lastname'               => new external_value(PARAM_TEXT, 'Student last name'),
                        'email'                  => new external_value(PARAM_TEXT, 'Student email'),
                        'lastaccess'             => new external_value(PARAM_INT, 'Last access timestamp.', VALUE_OPTIONAL),
                        'participatedactivities' => new external_value(PARAM_INT, 'Number of graded activities completed.'),
                        'totalactivities'        => new external_value(PARAM_INT, 'Total number of graded activities in course.'),
                        'participationrate'      => new external_value(PARAM_FLOAT, 'Participation rate in activities (%).'),
                    ]),
                    'List of ghost/inactive students.'
                ),
            ]),
        ]);
    }
}