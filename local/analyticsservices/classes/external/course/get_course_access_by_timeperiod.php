<?php

namespace local_analyticsservices\external\course;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;

defined('MOODLE_INTERNAL') || die();


class get_course_access_by_timeperiod extends external_api
{
    /**
     * Parameter function.
     */
    public static function execute_parameters()
    {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'unique_by_user' => new external_value(PARAM_BOOL, 'Count only one log per user', VALUE_DEFAULT, true),
            'periods' => new external_single_structure([
                'pagi' => new external_single_structure([
                    'start' => new external_value(PARAM_RAW, 'Jam mulai pagi (format HH:MM)', VALUE_DEFAULT, '05:00'),
                    'end'   => new external_value(PARAM_RAW, 'Jam akhir pagi (format HH:MM)', VALUE_DEFAULT, '07:59'),
                ]),
                'siang' => new external_single_structure([
                    'start' => new external_value(PARAM_RAW, 'Jam mulai siang (format HH:MM)', VALUE_DEFAULT, '08:00'),
                    'end'   => new external_value(PARAM_RAW, 'Jam akhir siang (format HH:MM)', VALUE_DEFAULT, '15:59'),
                ]),
                'malam' => new external_single_structure([
                    'start' => new external_value(PARAM_RAW, 'Jam mulai malam (format HH:MM)', VALUE_DEFAULT, '16:00'),
                    'end'   => new external_value(PARAM_RAW, 'Jam akhir malam (format HH:MM)', VALUE_DEFAULT, '04:59'),
                ]),
            ], 'Konfigurasi rentang waktu harian', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Eksekusi utama.
     */
    public static function execute($courseid, $unique_by_user = true, $periods = [])
    {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'unique_by_user' => $unique_by_user,
            'periods' => $periods,
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);

        // Ambil log "viewed" untuk course terkait.
        $records = $DB->get_records_sql(
            "SELECT id, userid, timecreated FROM {logstore_standard_log}
            WHERE courseid = :courseid AND action = :action",
            [
                'courseid' => $courseid,
                'action' => 'viewed'
            ]
        );

        $periodConfig = $params['periods'] ?: [
            'pagi' => ['start' => '05:00', 'end' => '07:59'],
            'siang' => ['start' => '08:00', 'end' => '15:59'],
            'malam' => ['start' => '16:00', 'end' => '04:59']
        ];

        $periods = array_fill_keys(array_keys($periodConfig), []);

        // Proses setiap record log.
        foreach ($records as $record) {
            $hour = (int) userdate($record->timecreated, '%H');
            $minute = (int) userdate($record->timecreated, '%M');
            $time = sprintf('%02d:%02d', $hour, $minute);

            foreach ($periodConfig as $key => $range) {
                $start = $range['start'];
                $end = $range['end'];

                $inRange = false;
                if ($start < $end) {
                    $inRange = ($time >= $start && $time <= $end);
                } else {
                    $inRange = ($time >= $start || $time <= $end);
                }

                if ($inRange) {
                    if ($unique_by_user) {
                        $periods[$key][$record->userid] = true;
                    } else {
                        $periods[$key][] = $record->userid;
                    }
                    break;
                }
            }
        }

        // Hitung hasil akhir.
        $results = [];
        foreach ($periods as $period => $data) {
            $count = $unique_by_user ? count($data) : count($data);
            $results[] = [
                'period' => $period,
                'access_count' => $count
            ];
        }

        return [
            'course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'time_periods' => $results
            ],
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
                'fullname' => new external_value(PARAM_TEXT, 'Full name of the course'),
                'shortname' => new external_value(PARAM_TEXT, 'Short name of the course'),
                'time_periods' => new external_multiple_structure(
                    new external_single_structure([
                        'period' => new external_value(PARAM_TEXT, 'Nama periode waktu'),
                        'access_count' => new external_value(PARAM_INT, 'Jumlah akses atau user unik pada periode tersebut')
                    ])
                )
            ]),
        ]);
    }
}
