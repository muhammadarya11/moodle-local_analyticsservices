<?php

namespace local_analyticsservices\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;

defined('MOODLE_INTERNAL') || die();

class get_course_access_by_ipgroup extends external_api
{

    public static function execute_parameters()
    {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'unique_by_user' => new external_value(PARAM_BOOL, 'Count by unique user', VALUE_OPTIONAL, true),
        ]);
    }

    /**
     * Ambil data jumlah akses course berdasarkan IP unik.
     */
    public static function execute($courseid, $unique_by_user = true)
    {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Ambil log akses berdasarkan IP.
        // Hanya event course yang relevan (course viewed dan activity viewed).
        if ($unique_by_user) {
            // Mode: hitung user unik per IP
            $sql = "SELECT ip, COUNT(DISTINCT userid) AS totalaccess
                FROM {logstore_standard_log}
                WHERE courseid = :courseid
                    AND ip IS NOT NULL
                    AND userid > 0
                    AND action = 'viewed'
                GROUP BY ip
                ORDER BY totalaccess DESC";
        } else {
            // Mode: hitung total log (tanpa unique user)
            $sql = "SELECT ip, COUNT(id) AS totalaccess
                FROM {logstore_standard_log}
                WHERE courseid = :courseid
                    AND ip IS NOT NULL
                    AND action = 'viewed'
                GROUP BY ip
                ORDER BY totalaccess DESC";
        }

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        $results = [];
        foreach ($records as $r) {
            $results[] = [
                'ip' => $r->ip,
                'access_count' => (int)$r->totalaccess
            ];
        }

        return ['ip_groups' => $results];
    }

    /**
     * Struktur output.
     */
    public static function execute_returns()
    {
        return new external_single_structure([
            'ip_groups' => new external_multiple_structure(
                new external_single_structure([
                    'ip' => new external_value(PARAM_TEXT, 'IP address'),
                    'access_count' => new external_value(PARAM_INT, 'Total access count from this IP'),
                ])
            )
        ]);
    }
}
