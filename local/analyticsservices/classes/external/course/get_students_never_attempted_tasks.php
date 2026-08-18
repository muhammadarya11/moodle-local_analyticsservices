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
 * External function to get students who never attempted tasks.
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
 * Class get_students_never_attempted_tasks.
 */
class get_students_never_attempted_tasks extends external_api {

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
        require_capability('moodle/grade:viewall', $context);

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);

        $students = helper::get_students_in_course($courseid);

        if (empty($students)) {
            return [
                'course' => [
                    'id'        => $course->id,
                    'fullname'  => $course->fullname,
                    'shortname' => $course->shortname,
                    'students'  => [],
                ],
            ];
        }

        $studentids = array_keys($students);

        // List gradeable modules.
        $modules = $DB->get_records_sql(
            "SELECT cm.id AS cmid, gi.itemmodule AS modname
               FROM {grade_items} gi
               JOIN {modules} m ON m.name = gi.itemmodule
               JOIN {course_modules} cm ON cm.module = m.id
                    AND cm.course = gi.courseid
                    AND cm.visible = 1
                    AND cm.deletioninprogress = 0
              WHERE gi.courseid = :courseid
                AND gi.itemtype = 'mod'
                AND gi.itemmodule IS NOT NULL",
            ['courseid' => $courseid]
        );

        if (empty($modules)) {
            return [
                'course' => [
                    'id'        => $course->id,
                    'fullname'  => $course->fullname,
                    'shortname' => $course->shortname,
                    'students'  => [],
                ],
            ];
        }

        $cmids = array_keys($modules);

        list($cmsql, $cmparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cmid');
        list($usersql, $userparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'userid');

        $sql = "SELECT DISTINCT userid
                  FROM {logstore_standard_log}
                 WHERE courseid = :courseid
                   AND contextinstanceid $cmsql
                   AND userid $usersql
                   AND action IN ('attempted', 'submitted')";

        $mergedparams = ['courseid' => $courseid] + $cmparams + $userparams;
        $activeusers  = $DB->get_records_sql($sql, $mergedparams);
        $activeids    = array_keys($activeusers);

        // Get students who are not in the active list.
        $inactiveids = array_diff($studentids, $activeids);

        $results = [];
        foreach ($inactiveids as $userid) {
            $user = $students[$userid];
            $results[] = [
                'id'        => $user->id,
                'username'  => $user->username,
                'firstname' => $user->firstname,
                'lastname'  => $user->lastname,
                'role'      => $user->roleshortname,
                'email'     => $user->email,
            ];
        }

        return [
            'course' => [
                'id'        => $course->id,
                'fullname'  => $course->fullname,
                'shortname' => $course->shortname,
                'students'  => $results,
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
                        'id'        => new external_value(PARAM_INT, 'User ID'),
                        'username'  => new external_value(PARAM_TEXT, 'Username'),
                        'firstname' => new external_value(PARAM_TEXT, 'First name'),
                        'lastname'  => new external_value(PARAM_TEXT, 'Last name'),
                        'email'     => new external_value(PARAM_TEXT, 'Email'),
                        'role'      => new external_value(PARAM_TEXT, 'Role'),
                    ])
                ),
            ]),
        ]);
    }
}
