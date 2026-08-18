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
 * External function to get course access by IP group.
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

/**
 * Class get_course_access_by_ipgroup.
 */
class get_course_access_by_ipgroup extends external_api {

    /**
     * Define the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid'       => new external_value(PARAM_INT, 'Course ID'),
            'unique_by_user' => new external_value(PARAM_BOOL, 'Count by unique user'),
        ]);
    }

    /**
     * Get course access count grouped by IP address.
     *
     * @param int $courseid Course ID.
     * @param bool $uniquebyuser Whether to count unique users per IP.
     * @return array
     */
    public static function execute($courseid, $uniquebyuser = true) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'       => $courseid,
            'unique_by_user' => $uniquebyuser,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('report/log:view', $context);

        $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, fullname, shortname', MUST_EXIST);

        // Get access logs grouped by IP (course viewed and activity viewed events only).
        if ($params['unique_by_user']) {
            // Mode: count unique users per IP.
            $sql = "SELECT ip, COUNT(DISTINCT userid) AS totalaccess
                      FROM {logstore_standard_log}
                     WHERE courseid = :courseid
                       AND ip IS NOT NULL
                       AND userid > 0
                       AND action = 'viewed'
                     GROUP BY ip
                     ORDER BY totalaccess DESC";
        } else {
            // Mode: count total logs (without unique user).
            $sql = "SELECT ip, COUNT(id) AS totalaccess
                      FROM {logstore_standard_log}
                     WHERE courseid = :courseid
                       AND ip IS NOT NULL
                       AND action = 'viewed'
                     GROUP BY ip
                     ORDER BY totalaccess DESC";
        }

        $records = $DB->get_records_sql($sql, ['courseid' => $params['courseid']]);

        $results = [];
        foreach ($records as $r) {
            $results[] = [
                'ip'           => $r->ip,
                'access_count' => (int)$r->totalaccess,
            ];
        }

        return [
            'course' => [
                'id'        => $course->id,
                'fullname'  => $course->fullname,
                'shortname' => $course->shortname,
                'ip_groups' => $results,
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
                'ip_groups' => new external_multiple_structure(
                    new external_single_structure([
                        'ip'           => new external_value(PARAM_TEXT, 'IP address'),
                        'access_count' => new external_value(PARAM_INT, 'Total access count from this IP.'),
                    ])
                ),
            ]),
        ]);
    }
}
