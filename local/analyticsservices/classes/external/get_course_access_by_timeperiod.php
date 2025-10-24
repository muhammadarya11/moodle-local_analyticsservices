<?php

namespace local_analyticsservices\external;

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
            'unique_by_user' => new external_value(PARAM_BOOL, 'Count by unique user', VALUE_OPTIONAL, true),
        ]);
    }


    /**
     * Eksekusi utama.
     */
    public static function execute($courseid, $unique_by_user = true)
    {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'unique_by_user' => $unique_by_user
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);


        // Ambil log "viewed" untuk course terkait.
        $records = $DB->get_records_sql(
            "SELECT id, userid, timecreated FROM {logstore_standard_log}
            WHERE courseid = :courseid AND action = :action",
            [
                'courseid' => $courseid,
                'action' => 'viewed'
            ]
        );

        // Inisialisasi periode waktu.
        $periods = [
            'pagi' => [],        // 05:00–07:59
            'jam_kerja' => [],   // 08:00–15:59
            'malam' => []        // 16:00–04:59
        ];

        foreach ($records as $record) {
            // Gunakan waktu lokal Moodle.
            $hour = (int) userdate($record->timecreated, '%H');

            if ($hour >= 5 && $hour < 8) {
                $periodkey = 'pagi';
            } elseif ($hour >= 8 && $hour < 16) {
                $periodkey = 'jam_kerja';
            } else {
                $periodkey = 'malam';
            }

            if ($unique_by_user) {
                // Simpan user unik per periode.
                $periods[$periodkey][$record->userid] = true;
            } else {
                // Simpan total log tanpa distinct.
                if (!isset($periods[$periodkey])) {
                    $periods[$periodkey] = [];
                }
                $periods[$periodkey][] = $record->userid;
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

        return ['time_periods' => $results];
    }

    /**
     * Struktur output.
     */
    public static function execute_returns()
    {
        return new external_single_structure([
            'time_periods' => new external_multiple_structure(
                new external_single_structure([
                    'period' => new external_value(PARAM_TEXT, 'Nama periode waktu'),
                    'access_count' => new external_value(PARAM_INT, 'Jumlah akses atau user unik pada periode tersebut')
                ])
            )
        ]);
    }
}
