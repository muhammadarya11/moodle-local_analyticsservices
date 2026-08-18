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
 * External function to get quiz attempt average time by section.
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
 * Class get_quiz_attempt_average_time_by_section.
 */
class get_quiz_attempt_average_time_by_section extends external_api {

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
        $section = $DB->get_record('course_sections', ['id' => $params['sectionid']], 'id, course', MUST_EXIST);
        $context = context_course::instance($section->course);
        self::validate_context($context);
        require_capability('moodle/grade:viewall', $context);

        // Get students enrolled in this course.
        $students = helper::get_students_in_course($section->course);
        if (empty($students)) {
            return [
                'courseid'  => $section->course,
                'sectionid' => $sectionid,
                'attempts'  => [],
            ];
        }

        $studentids = array_keys($students);
        list($studentsql, $studentparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'userid');

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
                AND qa.userid $studentsql
            GROUP BY qa.attempt
            ORDER BY qa.attempt",
            array_merge(['sectionid' => $sectionid], $studentparams)
        );

        return [
            'courseid'  => $section->course,
            'sectionid' => $sectionid,
            'attempts'  => array_values($records),
        ];
    }

    /**
     * Define the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'courseid'  => new external_value(PARAM_INT, 'Course ID'),
            'sectionid' => new external_value(PARAM_INT, 'Section ID'),
            'attempts'  => new external_multiple_structure(
                new external_single_structure([
                    'attempt_number'       => new external_value(PARAM_INT, 'Nomor attempt (1 = first attempt).'),
                    'avg_duration_minutes' => new external_value(PARAM_FLOAT, 'Rata-rata durasi pengerjaan dalam menit.'),
                    'total_attempts'       => new external_value(PARAM_INT, 'Total attempt yang dihitung pada attempt ini.'),
                ])
            ),
        ]);
    }
}
