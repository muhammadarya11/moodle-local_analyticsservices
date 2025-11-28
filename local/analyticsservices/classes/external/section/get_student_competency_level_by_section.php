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

class get_student_competency_level_by_section extends external_api
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
                'levels' => [
                    'competent' => 0,
                    'partially_competent' => 0,
                    'not_competent' => 0,
                ],
            ];
        }
    }

    /**
     * Struktur output.
     */
    public static function execute_returns() {}
}
