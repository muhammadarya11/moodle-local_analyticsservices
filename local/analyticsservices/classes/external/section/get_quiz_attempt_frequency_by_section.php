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

class get_quiz_attempt_frequency_by_section extends external_api
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

        $modulesquiz = $DB->get_records_sql(
            "SELECT cm.id AS cmid, q.id AS quizid, q.name AS quizname, cm.section, m.name AS modname
            FROM {course_modules} cm
            JOIN {modules} m ON m.id = cm.module
            JOIN {quiz} q ON q.id = cm.instance
            WHERE cm.section = :sectionid
            AND cm.visible = 1
            AND cm.deletioninprogress = 0
            AND m.name = :modulename",
            ['sectionid' => $sectionid, 'modulename' => 'quiz']
        );

        if (empty($modulesquiz)) {
            return [
                'courseid' => $section->course,
                'sectionid' => $sectionid,
                'quiz' => [],
            ];
        }

        $quizids = array_map(fn($m) => $m->quizid, $modulesquiz);
        list($quiz_sql, $quiz_params) = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'quizid');

        // Ambil data mahasiswa yang enroll di course ini
        $students = helper::get_students_in_course($section->course);
        if (empty($students)) {
            return [
                'courseid' => $section->course,
                'sectionid' => $sectionid,
                'quiz' => [],
            ];
        }

        $studentids = array_keys($students);
        list($student_sql, $student_params) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'userid');

        // Hitung berapa kali attempt kuis
        $sql = "SELECT
            CONCAT(t.quiz, '-', t.attempt_count) AS uniqueid,
            t.quiz as quizid,
            t.quizname,
            t.attempt_count,
            COUNT(t.userid) AS total_users
        FROM (
            SELECT 
                qa.quiz,
                q.name AS quizname,
                qa.userid,
                COUNT(qa.id) AS attempt_count
            FROM {quiz_attempts} qa
            JOIN {quiz} q ON q.id = qa.quiz
            WHERE qa.quiz $quiz_sql
              AND qa.userid $student_sql
              AND qa.preview = 0
              AND qa.state IN ('finished', 'inprogress')
            GROUP BY qa.quiz, qa.userid
        ) t
        GROUP BY t.quiz, t.attempt_count
        ORDER BY t.quiz, t.attempt_count";

        $frequencies = $DB->get_records_sql($sql, array_merge($quiz_params, $student_params));

        $result = [];
        foreach ($frequencies as $f) {
            $quizid = $f->quizid;

            if (!isset($result[$quizid])) {
                $result[$quizid] = [
                    'quizid' => $f->quizid,
                    'quizname' => $f->quizname,
                    'frequencies' => []
                ];
            }

            $result[$quizid]['frequencies'][] = [
                'attempt_count' => (int)$f->attempt_count,
                'total_users' => (int)$f->total_users
            ];
        }

        return [
            'courseid' => $section->course,
            'sectionid' => $sectionid,
            'quiz' => array_values($result)
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
            'quiz' => new external_multiple_structure(
                new external_single_structure([
                    'quizid' => new external_value(PARAM_INT, 'Quiz ID'),
                    'quizname' => new external_value(PARAM_TEXT, 'Nama quiz'),
                    'frequencies' => new external_multiple_structure(
                        new external_single_structure([
                            'attempt_count' => new external_value(PARAM_INT, 'Jumlah percobaan attempt'),
                            'total_users' => new external_value(PARAM_INT, 'Total user yang melakukan attempt sebanyak itu'),
                        ])
                    ),
                ])
            ),
        ]);
    }
}
