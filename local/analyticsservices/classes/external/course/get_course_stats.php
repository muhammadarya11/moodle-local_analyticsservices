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
 * External function to get course statistics.
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
use core\exception\invalid_parameter_exception;
use DateInterval;
use DatePeriod;
use DateTime;
use local_analyticsservices\helper;

/**
 * Class get_course_stats.
 */
class get_course_stats extends external_api {

    /**
     * Define the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid'  => new external_value(PARAM_INT, 'Course ID'),
            'startdate' => new external_value(PARAM_TEXT, 'Start date (Y-m-d)'),
            'enddate'   => new external_value(PARAM_TEXT, 'End date (Y-m-d)'),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $courseid Course ID.
     * @param string $startdate Start date.
     * @param string $enddate End date.
     * @return array
     */
    public static function execute($courseid, $startdate, $enddate) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'  => $courseid,
            'startdate' => $startdate,
            'enddate'   => $enddate,
        ]);

        // Validate context and capability FIRST before any business logic.
        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('report/log:view', $context);

        $start = strtotime($params['startdate'] . ' 00:00:00');
        $end   = strtotime($params['enddate'] . ' 23:59:59');

        if ($start === false || $end === false) {
            throw new invalid_parameter_exception('Invalid date format, expected Y-m-d.');
        }

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);

        // Get students enrolled in this course.
        $students      = helper::get_students_in_course($courseid);
        $totalstudents = count($students);

        if ($totalstudents == 0) {
            return [
                'course' => [
                    'id'        => $course->id,
                    'fullname'  => $course->fullname,
                    'shortname' => $course->shortname,
                    'stats'     => [],
                ],
            ];
        }

        list($studentsql, $studentparams) = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED, 'studentid');

        $sql = "SELECT id, courseid, action, crud, userid, timecreated
                  FROM {logstore_standard_log} log
                 WHERE log.courseid = :courseid
                   AND log.origin = 'web'
                   AND log.userid $studentsql
                   AND log.timecreated BETWEEN :start AND :end
                 ORDER BY log.timecreated ASC";

        $records = $DB->get_records_sql(
            $sql,
            array_merge(['courseid' => $courseid, 'start' => $start, 'end' => $end], $studentparams)
        );

        $grouped = [];

        foreach ($records as $record) {
            $label = date('o-W', $record->timecreated);

            if (!isset($grouped[$label])) {
                $grouped[$label] = [
                    'views' => 0,
                    'posts' => 0,
                ];
            }

            if ($record->crud === 'r') {
                $grouped[$label]['views']++;
            } else {
                $grouped[$label]['posts']++;
            }
        }

        $filled = [];

        $startobj = (new DateTime())->setTimestamp($start);
        $endobj   = (new DateTime())->setTimestamp($end);

        // Fill empty data in certain periods.
        $startobj->modify('monday this week');
        $endobj->modify('monday next week');

        $period = new DatePeriod(
            $startobj,
            new DateInterval('P1W'),
            $endobj
        );

        foreach ($period as $week) {
            $label = $week->format('o-W'); // ISO week.
            $filled[$label] = [
                'label' => $label,
                'views' => $grouped[$label]['views'] ?? 0,
                'posts' => $grouped[$label]['posts'] ?? 0,
            ];
        }

        return [
            'course' => [
                'id'        => $course->id,
                'fullname'  => $course->fullname,
                'shortname' => $course->shortname,
                'stats'     => array_values($filled),
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
                'stats'     => new external_multiple_structure(
                    new external_single_structure([
                        'label' => new external_value(PARAM_TEXT, 'Date label'),
                        'views' => new external_value(PARAM_INT, 'Number of views'),
                        'posts' => new external_value(PARAM_INT, 'Number of posts'),
                    ]),
                    'List of course statistics grouped by date.'
                ),
            ]),
        ]);
    }
}
