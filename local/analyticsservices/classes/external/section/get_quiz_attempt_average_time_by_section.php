<?php

namespace local_analyticsservices\external\section;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;

use local_analyticsservices\helper;

defined('MOODLE_INTERNAL') || die();

class get_quiz_attempt_average_time_by_section extends external_api
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
        if (empty($students)) {
            return [
                'courseid' => $section->course,
                'sectionid' => $sectionid,
                'attempts' => [],
            ];
        }

        $studentids = array_keys($students);
        list($student_sql, $student_params) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'userid');

        $records = $DB->get_records_sql(
            "SELECT qa.attempt AS attempt_number,
                ROUND(AVG((qa.timefinish - qa.timestart)/60), 2) AS avg_duration_minutes,
                COUNT(qa.id) AS total_attempts
            FROM {quiz_attempts} qa
            JOIN {quiz} q ON q.id = qa.quiz
            JOIN {course_modules} cm ON cm.instance = q.id
            JOIN {modules} m ON m.id = cm.module
            WHERE cm.section = :sectionid
                AND m.name = 'quiz'
                AND qa.preview = 0
                AND qa.state IN ('finished', 'inprogress')
                AND qa.timefinish > 0
                AND qa.userid $student_sql
            GROUP BY qa.attempt
            ORDER BY qa.attempt;",
            array_merge(['sectionid' => $sectionid], $student_params)
        );

        return [
            'courseid' => $section->course,
            'sectionid' => $sectionid,
            'attempts' => array_values($records), // pastikan hasil jadi array numerik
        ];
    }

    /**
     * Struktur output.
     */
    public static function execute_returns()
    {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionid' => new external_value(PARAM_INT, 'Section ID'),
            'attempts' => new external_multiple_structure(
                new external_single_structure([
                    'attempt_number' => new external_value(PARAM_INT, 'Nomor attempt (1 = first attempt, dst)'),
                    'avg_duration_minutes' => new external_value(PARAM_FLOAT, 'Rata-rata durasi pengerjaan dalam menit'),
                    'total_attempts' => new external_value(PARAM_INT, 'Total attempt yang dihitung pada attempt ini'),
                ])
            )
        ]);
    }
}
