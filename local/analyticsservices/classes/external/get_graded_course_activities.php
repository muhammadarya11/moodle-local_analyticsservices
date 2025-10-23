<?php

namespace local_analyticsservices\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;


defined('MOODLE_INTERNAL') || die();

class get_graded_course_activities extends external_api
{

    public static function execute_parameters()
    {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Hitung persentase aktivitas yang sudah dinilai.
     */
    public static function execute($courseid)
    {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Ambil semua user yang terdaftar (biasanya mahasiswa).
        $students = get_enrolled_users($context, 'mod/assign:submit');
        $totalstudents = count($students);

        // Kalau tidak ada mahasiswa, return kosong.
        if ($totalstudents === 0) {
            return ['activities' => []];
        }

        // Ambil semua aktivitas yang memiliki grade item (modul apapun).
        $sql = "SELECT gi.id AS gradeitemid,
                       gi.itemname,
                       gi.itemmodule,
                       gi.iteminstance,
                       COUNT(DISTINCT g.userid) AS gradedcount
                FROM {grade_items} gi
                JOIN {modules} m ON m.name = gi.itemmodule
                JOIN {course_modules} cm ON cm.module = m.id
                    AND cm.instance = gi.iteminstance
                    AND cm.deletioninprogress = 0
                LEFT JOIN {grade_grades} g ON g.itemid = gi.id AND g.finalgrade IS NOT NULL
                    WHERE gi.courseid = :courseid
                    AND gi.itemtype = 'mod'
                GROUP BY gi.id, gi.itemname, gi.itemmodule, gi.iteminstance
                ORDER BY gi.itemmodule, gi.itemname";

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        $results = [];
        foreach ($records as $r) {
            $percentage = 0;
            if ($totalstudents > 0 && $r->gradedcount > 0) {
                $percentage = round(($r->gradedcount / $totalstudents) * 100, 2);
            }

            $results[] = [
                'id' => (int)$r->iteminstance,
                'name' => $r->itemname ?? 'Unnamed activity',
                'module' => $r->itemmodule ?? 'unknown',
                'percentage_graded' => $percentage
            ];
        }

        return ['activities' => $results];
    }

    /**
     * Struktur output.
     */
    public static function execute_returns()
    {
        return new external_single_structure([
            'activities' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Activity instance ID'),
                    'name' => new external_value(PARAM_TEXT, 'Activity name'),
                    'module' => new external_value(PARAM_TEXT, 'Module type'),
                    'percentage_graded' => new external_value(PARAM_FLOAT, 'Percentage of students graded')
                ])
            )
        ]);
    }
}
