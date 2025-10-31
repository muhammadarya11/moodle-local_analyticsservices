<?php

namespace local_analyticsservices\external\section;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;
use core_component;

use local_analyticsservices\helper;

defined('MOODLE_INTERNAL') || die();

class get_grade_distribution_by_section extends external_api
{
    public static function execute_parameters()
    {
        return new external_function_parameters([
            'sectionid' => new external_value(PARAM_INT, 'Section ID'),
        ]);
    }

    public static function execute($sectionid)
    {
        global $DB;

        // Validasi parameter
        $params = self::validate_parameters(self::execute_parameters(), [
            'sectionid' => $sectionid
        ]);

        // Validasi context course
        $section = $DB->get_record('course_sections', ['id' => $sectionid], 'id, course', MUST_EXIST);
        $context = context_course::instance($section->course);
        self::validate_context($context);

        // Ambil data mahasiswa yang enroll di course ini
        $students = helper::get_students_in_course($section->course);
        $studentids = array_keys($students);
        if (empty($students)) {
            return [
                'courseid' => $section->course,
                'sectionid' => $sectionid,
                'grades' => [],
            ];
        }

        list($student_sql, $student_params) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'userid');

        // Ambil data module yang bisa dinilai
        $sql = "SELECT gi.id AS gradeitemid,
            gi.iteminstance AS cmid, 
            g.userid, 
            gi.itemname, 
            gi.itemmodule,
            ROUND((g.finalgrade / gi.grademax * 100), 2) AS percentage
        FROM {grade_items} gi
        JOIN {modules} m ON m.name = gi.itemmodule
        JOIN {course_modules} cm ON cm.module = m.id
            AND cm.instance = gi.iteminstance
            AND cm.deletioninprogress = 0
            AND cm.visible = 1
        JOIN {grade_grades} g ON g.itemid = gi.id 
            AND g.finalgrade IS NOT NULL
            AND g.userid $student_sql
        WHERE gi.courseid = :courseid
            AND cm.section = :sectionid
            AND gi.itemtype = 'mod'
            AND gi.grademax > 0
        ORDER BY g.userid, gi.itemmodule, gi.itemname";

        $grades = $DB->get_records_sql($sql, array_merge($student_params, [
            'courseid' => $section->course,
            'sectionid' => $sectionid
        ]));

        if (empty($grades)) {
            return [
                'courseid' => $section->course,
                'sectionid' => $sectionid,
                'grades' => [],
            ];
        }

        // Inisialisasi rentang nilai
        $ranges = [
            'A' => ['min' => 90, 'max' => 100, 'total' => 0],
            'B' => ['min' => 80, 'max' => 89,  'total' => 0],
            'C' => ['min' => 70, 'max' => 79,  'total' => 0],
            'D' => ['min' => 60, 'max' => 69,  'total' => 0],
            'E' => ['min' => 0,  'max' => 59,  'total' => 0],
        ];

        // Hitung distribusi nilai per mahasiswa (gunakan rata-rata antar aktivitas jika perlu)
        $userGrades = [];
        foreach ($grades as $g) {
            $userGrades[$g->userid][] = $g->percentage;
        }

        // Hitung rata-rata per user dan kelompokkan ke range
        foreach ($userGrades as $userid => $percentages) {
            $avg = array_sum($percentages) / count($percentages);

            foreach ($ranges as $label => &$range) {
                if ($avg >= $range['min'] && $avg <= $range['max']) {
                    $range['total']++;
                    break;
                }
            }
        }

        return [
            'courseid' => (int)$section->course,
            'sectionid' => (int)$sectionid,
            'grades' => array_map(function ($label, $range) {
                return [
                    'grade_range' => $label,
                    'min' => $range['min'],
                    'max' => $range['max'],
                    'total_students' => $range['total']
                ];
            }, array_keys($ranges), $ranges)
        ];
    }

    /**
     * Struktur output.
     */
    public static function execute_returns()
    {
        return new external_single_structure(
            [
                'courseid' => new external_value(PARAM_INT, 'ID course'),
                'sectionid' => new external_value(PARAM_INT, 'ID section'),
                'grades' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'grade_range' => new external_value(PARAM_TEXT, 'Rentang nilai, misalnya A/B/C/D/E'),
                            'min' => new external_value(PARAM_FLOAT, 'Nilai minimum rentang'),
                            'max' => new external_value(PARAM_FLOAT, 'Nilai maksimum rentang'),
                            'total_students' => new external_value(PARAM_INT, 'Jumlah mahasiswa dalam rentang nilai ini')
                        ]
                    ),
                    'Daftar distribusi nilai berdasarkan rentang'
                )
            ]
        );
    }
}
