<?php

namespace local_analyticsservices\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;

defined('MOODLE_INTERNAL') || die();

class get_uncompetent_activities extends external_api
{
    public static function execute_parameters()
    {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'threshold' => new external_value(PARAM_FLOAT, 'Nilai batas kompetensi (default 80)', VALUE_DEFAULT, 80.0)
        ]);
    }


    public static function execute($courseid, $threshold = 80.0)
    {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'threshold' => $threshold
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Get student role id
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
        if (!$studentroleid) $studentroleid = 5;

        $students = get_role_users($studentroleid, $context);
        if (empty($students)) {
            return ['activities' => []];
        }

        // List modules atau aktivitas yang bisa dinilai atau memiliki grade
        $modules = $DB->get_records_sql(
            "SELECT DISTINCT
                gi.id AS gradeitemid,
                gi.itemname AS name,
                gi.itemmodule AS modname,
                cm.id AS cmid
            FROM {grade_items} gi
            JOIN {modules} m
                ON m.name = gi.itemmodule
            JOIN {course_modules} cm
                ON cm.module = m.id
                AND cm.instance = gi.iteminstance
                AND cm.course = gi.courseid
                AND cm.visible = 1
                AND cm.deletioninprogress = 0
            WHERE gi.courseid = :courseid
                AND gi.itemtype = 'mod'
                AND gi.itemmodule IS NOT NULL",
            ['courseid' => $courseid]
        );

        if (empty($modules)) {
            return ['activities' => []];
        }

        // Ambil semua grade sekaligus untuk semua grade item di course ini.
        list($gradeitem_sql, $gradeitem_params) = $DB->get_in_or_equal(
            array_column($modules, 'gradeitemid'),
            SQL_PARAMS_NAMED,
            'giid'
        );

        $grades_all = $DB->get_records_sql(
            "SELECT id, userid, itemid, finalgrade
            FROM {grade_grades}
            WHERE itemid $gradeitem_sql",
            $gradeitem_params
        );

        // Mapping student IDs agar mudah dihitung
        $studentids = array_keys($students);
        $totalstudents = count($studentids);

        // Index semua grade berdasarkan itemid
        $grades_by_item = [];
        foreach ($grades_all as $g) {
            $grades_by_item[$g->itemid][$g->userid] = $g->finalgrade;
        }

        $result = [];

        foreach ($modules as $m) {
            $itemid = $m->gradeitemid;

            $uncompetent = 0;
            foreach ($studentids as $sid) {
                // Jika mahasiswa belum punya nilai atau nilainya di bawah ambang batas
                if (
                    !isset($grades_by_item[$itemid][$sid]) ||
                    is_null($grades_by_item[$itemid][$sid]) ||
                    $grades_by_item[$itemid][$sid] <= $threshold
                ) {
                    $uncompetent++;
                }
            }

            $percent_uncompetent = $totalstudents > 0
                ? round(($uncompetent / $totalstudents) * 100, 2)
                : 0.0;

            // Hanya tambahkan jika semua mahasiswa belum kompeten
            if ($percent_uncompetent == 100.0) {
                $result[] = [
                    'cmid' => $m->cmid,
                    'modname' => $m->modname,
                    'itemname' => $m->name ?? '',
                    'percent_uncompetent' => $percent_uncompetent
                ];
            }
        }

        return ['activities' => $result];
    }

    public static function execute_returns()
    {
        return new external_single_structure([
            'activities' => new external_multiple_structure(
                new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module ID'),
                    'modname' => new external_value(PARAM_TEXT, 'Nama modul (assign, quiz, dsb)'),
                    'itemname' => new external_value(PARAM_TEXT, 'Nama aktivitas'),
                    'percent_uncompetent' => new external_value(PARAM_FLOAT, 'Persentase mahasiswa belum kompeten'),
                ])
            )
        ]);
    }
}
