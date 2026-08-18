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
 * External function to get quiz attempt frequency by section.
 *
 * @package   local_analyticsservices
 * @copyright 2026, Arya Kusuma <muhammadaryakusuma@gmail.com>
 * @copyright 2026, Safiyyah Yahya <safiyyahyahya163@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_analyticsservices\external\section;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;
use local_analyticsservices\helper;

/**
 * Class get_quiz_attempt_frequency_by_section.
 */
class get_quiz_attempt_frequency_by_section extends external_api {

    /**
     * Define the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'sectionid' => new external_value(PARAM_INT, 'Section ID'),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $sectionid Section ID.
     * @return array
     */
    public static function execute($sectionid) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'sectionid' => $sectionid,
        ]);

        // Validate course context.
        $section = $DB->get_record('course_sections', ['id' => $params['sectionid']], 'id, course, name', MUST_EXIST);
        $context = context_course::instance($section->course);
        self::validate_context($context);
        require_capability('moodle/grade:viewall', $context);

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
                'section' => [
                    'id'       => $section->id,
                    'name'     => $section->name,
                    'courseid' => $section->course,
                    'quiz'     => [],
                ],
            ];
        }

        $quizids = array_map(fn($m) => $m->quizid, $modulesquiz);
        list($quizsql, $quizparams) = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'quizid');

        // Get students enrolled in this course.
        $students = helper::get_students_in_course($section->course);
        if (empty($students)) {
            return [
                'section' => [
                    'id'       => $section->id,
                    'name'     => $section->name,
                    'courseid' => $section->course,
                    'quiz'     => [],
                ],
            ];
        }

        $studentids = array_keys($students);
        list($studentsql, $studentparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'userid');

        // Count how many times each student attempted each quiz.
        $sql = "SELECT
                    CONCAT(t.quiz, '-', t.attempt_count) AS uniqueid,
                    t.quiz AS quizid,
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
                    WHERE qa.quiz $quizsql
                      AND qa.userid $studentsql
                      AND qa.preview = 0
                      AND qa.state IN ('finished', 'inprogress')
                    GROUP BY qa.quiz, qa.userid
                ) t
                GROUP BY t.quiz, t.attempt_count
                ORDER BY t.quiz, t.attempt_count";

        $frequencies = $DB->get_records_sql($sql, array_merge($quizparams, $studentparams));

        $result = [];
        foreach ($frequencies as $f) {
            $quizid = $f->quizid;

            if (!isset($result[$quizid])) {
                $result[$quizid] = [
                    'quizid'      => $f->quizid,
                    'quizname'    => $f->quizname,
                    'frequencies' => [],
                ];
            }

            $result[$quizid]['frequencies'][] = [
                'attempt_count' => (int)$f->attempt_count,
                'total_users'   => (int)$f->total_users,
            ];
        }

        return [
            'section' => [
                'id'       => $section->id,
                'name'     => $section->name,
                'courseid' => $section->course,
                'quiz'     => array_values($result),
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
            'section' => new external_single_structure([
                'id'       => new external_value(PARAM_INT, 'Section ID'),
                'name'     => new external_value(PARAM_TEXT, 'Section name'),
                'courseid' => new external_value(PARAM_INT, 'Course ID'),
                'quiz'     => new external_multiple_structure(
                    new external_single_structure([
                        'quizid'      => new external_value(PARAM_INT, 'Quiz ID'),
                        'quizname'    => new external_value(PARAM_TEXT, 'Quiz name'),
                        'frequencies' => new external_multiple_structure(
                            new external_single_structure([
                                'attempt_count' => new external_value(PARAM_INT, 'Number of attempts.'),
                                'total_users'   => new external_value(PARAM_INT, 'Total users with this attempt count.'),
                            ]),
                            'List of attempt frequencies.',
                            VALUE_DEFAULT
                        ),
                    ]),
                    'List of quizzes in the section.',
                    VALUE_DEFAULT
                ),
            ]),
        ]);
    }
}
