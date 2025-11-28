<?php

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

defined('MOODLE_INTERNAL') || die();

class get_course_stats extends external_api
{
    public static function execute_parameters()
    {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'startdate' => new external_value(PARAM_TEXT, 'Start date (Y-m-d)'),
            'enddate'   => new external_value(PARAM_TEXT, 'End date (Y-m-d)'),
        ]);
    }

    public static function execute($courseid, $startdate, $enddate)
    {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'startdate' => $startdate,
            'enddate' => $enddate,
        ]);

        $start = strtotime($params['startdate'] . ' 00:00:00');
        $end   = strtotime($params['enddate'] . ' 23:59:59');


        if ($start === false || $end === false) {
            throw new invalid_parameter_exception('Invalid date format, expected Y-m-d');
        }

        $context = context_course::instance($courseid);
        self::validate_context($context);

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);

        // Ambil data mahasiswa yang enroll di course ini.
        $students = helper::get_students_in_course($courseid);
        $totalstudents = count($students);

        if ($totalstudents == 0) {
            return [
                'course' => [
                    'id' => $course->id,
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname,
                    'statsmode' => '',
                    'stats' => []
                ]
            ];
        }

        list($student_sql, $student_params) = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED, 'studentid');

        $sql = "SELECT id, courseid, action, crud, userid, timecreated FROM {logstore_standard_log} log
                WHERE log.courseid = :courseid 
                AND log.origin = 'web'
                AND log.userid $student_sql
                AND log.timecreated BETWEEN :start AND :end
                ORDER BY log.timecreated ASC";

        $records = $DB->get_records_sql(
            $sql,
            array_merge(['courseid' => $courseid, 'start' => $start, 'end' => $end], $student_params)
        );

        // Grouping mode berdasarkan rentang tanggal.
        $diff = $end - $start;
        if ($diff <= 7 * 86400) {
            $groupmode = 'day';
        } else if ($diff <= 31 * 86400) {
            $groupmode = 'week';
        } else {
            $groupmode = 'month';
        }

        $grouped = [];

        foreach ($records as $record) {
            if ($groupmode === 'day') {
                $label = date('Y-m-d', $record->timecreated);
            } else if ($groupmode === 'week') {
                $label = date('o-W', $record->timecreated); // ISO week
            } else { // month
                $label = date('Y-m', $record->timecreated);
            }

            if (!isset($grouped[$label])) {
                $grouped[$label] = [
                    'views' => 0,
                    'posts' => 0
                ];
            }

            if ($record->crud === 'r') {
                $grouped[$label]['views']++;
            } else {
                $grouped[$label]['posts']++;
            }
        }

        $filled = [];

        $startObj = (new DateTime())->setTimestamp($start);
        $endObj   = (new DateTime())->setTimestamp($end);

        // Isi data kosong pada periode tertentu.
        if ($groupmode === 'day') {

            $period = new DatePeriod(
                $startObj,
                new DateInterval('P1D'),
                $endObj,
            );

            foreach ($period as $d) {
                $label = $d->format('Y-m-d');
                $filled[$label] = [
                    'label' => $label,
                    'views' => $grouped[$label]['views'] ?? 0,
                    'posts' => $grouped[$label]['posts'] ?? 0
                ];
            }
        } else if ($groupmode === 'week') {

            // Normalize start to Monday (ISO 8601)
            $startObj->modify('monday this week');
            $endObj->modify('monday next week');

            $period = new DatePeriod(
                $startObj,
                new DateInterval('P1W'),
                $endObj
            );

            foreach ($period as $week) {
                $label = $week->format('o-W'); // ISO week
                $filled[$label] = [
                    'label' => $label,
                    'views' => $grouped[$label]['views'] ?? 0,
                    'posts' => $grouped[$label]['posts'] ?? 0
                ];
            }
        } else { // month

            $startObj->modify('first day of this month');
            $endObj->modify('last day of this month');

            $period = new DatePeriod(
                $startObj,
                new DateInterval('P1M'),
                $endObj
            );

            foreach ($period as $m) {
                $label = $m->format('Y-m');
                $filled[$label] = [
                    'label' => $label,
                    'views' => $grouped[$label]['views'] ?? 0,
                    'posts' => $grouped[$label]['posts'] ?? 0
                ];
            }
        }

        return [
            'course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'statsmode' => $groupmode,
                'stats' => array_values($filled),
            ]
        ];
    }

    /**
     * Struktur output.
     */
    public static function execute_returns()
    {
        return new external_single_structure([
            'course' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course ID'),
                'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
                'statsmode' => new external_value(PARAM_TEXT, 'Grouping mode (day, week, month)'),
                'stats' => new external_multiple_structure(
                    new external_single_structure([
                        'label' => new external_value(PARAM_TEXT, 'Date label'),
                        'views' => new external_value(PARAM_INT, 'Number of views'),
                        'posts' => new external_value(PARAM_INT, 'Number of posts'),
                    ]),
                    'List of course statistics grouped by date'
                )
            ])
        ]);
    }
}
