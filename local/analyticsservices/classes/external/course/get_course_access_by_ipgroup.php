<?php

namespace local_analyticsservices\external\course;

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
            'unique_by_user' => new external_value(PARAM_BOOL, 'Count by unique user'),
        ]);
    }

    /**
     * Ambil data jumlah akses course berdasarkan IP unik.
     */
    public static function execute($courseid, $unique_by_user = true)
    {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'unique_by_user' => $unique_by_user
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, fullname, shortname', MUST_EXIST);

        // Ambil log akses berdasarkan IP.
        // Hanya event course yang relevan (course viewed dan activity viewed).
        if ($params['unique_by_user']) {
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

        $records = $DB->get_records_sql($sql, ['courseid' => $params['courseid']]);

        $results = [];
        foreach ($records as $r) {
            $results[] = [
                'ip' => $r->ip,
                'access_count' => (int)$r->totalaccess
            ];
        }

        return [
            'course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'ip_groups' => $results
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
                'ip_groups' => new external_multiple_structure(
                    new external_single_structure([
                        'ip' => new external_value(PARAM_TEXT, 'IP address'),
                        'access_count' => new external_value(PARAM_INT, 'Total access count from this IP'),
                    ])
                )
            ]),
        ]);
    }
}
