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
 * External function to get course access by time period.
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

/**
 * Class get_course_access_by_timeperiod.
 */
class get_course_access_by_timeperiod extends external_api {

    /**
     * Define the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid'       => new external_value(PARAM_INT, 'Course ID'),
            'unique_by_user' => new external_value(PARAM_BOOL, 'Count only one log per user', VALUE_DEFAULT, true),
            'periods'        => new external_single_structure([
                'pagi'  => new external_single_structure([
                    'start' => new external_value(PARAM_TEXT, 'Jam mulai pagi (format HH:MM).', VALUE_DEFAULT, '05:00'),
                    'end'   => new external_value(PARAM_TEXT, 'Jam akhir pagi (format HH:MM).', VALUE_DEFAULT, '07:59'),
                ]),
                'siang' => new external_single_structure([
                    'start' => new external_value(PARAM_TEXT, 'Jam mulai siang (format HH:MM).', VALUE_DEFAULT, '08:00'),
                    'end'   => new external_value(PARAM_TEXT, 'Jam akhir siang (format HH:MM).', VALUE_DEFAULT, '15:59'),
                ]),
                'malam' => new external_single_structure([
                    'start' => new external_value(PARAM_TEXT, 'Jam mulai malam (format HH:MM).', VALUE_DEFAULT, '16:00'),
                    'end'   => new external_value(PARAM_TEXT, 'Jam akhir malam (format HH:MM).', VALUE_DEFAULT, '04:59'),
                ]),
            ], 'Konfigurasi rentang waktu harian.', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $courseid Course ID.
     * @param bool $uniquebyuser Count unique users.
     * @param array $periods Time period configuration.
     * @return array
     */
    public static function execute($courseid, $uniquebyuser = true, $periods = []) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'       => $courseid,
            'unique_by_user' => $uniquebyuser,
            'periods'        => $periods,
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('report/log:view', $context);

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);

        $periodconfig = $params['periods'] ?: [
            'pagi'  => ['start' => '05:00', 'end' => '07:59'],
            'siang' => ['start' => '08:00', 'end' => '15:59'],
            'malam' => ['start' => '16:00', 'end' => '04:59'],
        ];

        // Validasi format.
        $timeregex = '/^([01]\d|2[0-3]):[0-5]\d$/';
        $periodconfigminutes = [];
        foreach ($periodconfig as $periodname => $range) {
            foreach (['start', 'end'] as $key) {
                if (!preg_match($timeregex, $range[$key])) {
                    throw new invalid_parameter_exception(
                        "Invalid time format for period '$periodname.$key': '{$range[$key]}'. Expected HH:MM."
                    );
                }
            }

            $startparts = explode(':', $range['start']);
            $endparts   = explode(':', $range['end']);
            $periodconfigminutes[$periodname] = [
                'start' => (int)$startparts[0] * 60 + (int)$startparts[1],
                'end'   => (int)$endparts[0] * 60 + (int)$endparts[1],
            ];
        }

        // OPTIMASI: Pindahkan logika klasifikasi waktu dari PHP murni ke dalam SQL Engine.
        $tz = \core_date::get_user_timezone_object();
        $offset = $tz->getOffset(new \DateTime('now', new \DateTimeZone('UTC')));

        // Kita gunakan $DB->sql_modulo() agar cross-database compatible (MySQL & Postgres).
        $modulo = $DB->sql_modulo("timecreated + $offset", 86400);

        // Memaksa floor int behavior pada database.
        $localminute = "FLOOR($modulo / 60)";

        $selects = [];
        foreach ($periodconfigminutes as $key => $range) {
            $start = $range['start'];
            $end = $range['end'];

            if ($start <= $end) {
                $cond = "($localminute >= $start AND $localminute <= $end)";
            } else {
                $cond = "($localminute >= $start OR $localminute <= $end)";
            }

            if ($params['unique_by_user']) {
                $selects[] = "COUNT(DISTINCT CASE WHEN $cond THEN userid END) AS {$key}_count";
            } else {
                $selects[] = "COUNT(CASE WHEN $cond THEN userid END) AS {$key}_count";
            }
        }

        $sql = "SELECT " . implode(', ', $selects) . "
                FROM {logstore_standard_log}
                WHERE courseid = :courseid AND action = 'viewed'";

        // Hit ratusan ribu baris akan dihitung instan oleh SQL.
        $record = $DB->get_record_sql($sql, ['courseid' => $courseid]);

        $results = [];
        foreach ($periodconfig as $period => $data) {
            $field = "{$period}_count";
            $results[] = [
                'period'       => $period,
                'access_count' => (int) ($record->$field ?? 0),
            ];
        }

        return [
            'course' => [
                'id'           => $course->id,
                'fullname'     => $course->fullname,
                'shortname'    => $course->shortname,
                'time_periods' => $results,
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
                'id'           => new external_value(PARAM_INT, 'Course ID'),
                'fullname'     => new external_value(PARAM_TEXT, 'Full name of the course'),
                'shortname'    => new external_value(PARAM_TEXT, 'Short name of the course'),
                'time_periods' => new external_multiple_structure(
                    new external_single_structure([
                        'period'       => new external_value(PARAM_TEXT, 'Nama periode waktu.'),
                        'access_count' => new external_value(PARAM_INT, 'Jumlah akses atau user unik pada periode tersebut.'),
                    ])
                ),
            ]),
        ]);
    }
}