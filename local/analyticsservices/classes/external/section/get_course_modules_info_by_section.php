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

class get_course_modules_info_by_section extends external_api
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
        $section = $DB->get_record('course_sections', ['id' => $sectionid], 'id, course, name', MUST_EXIST);
        $context = context_course::instance($section->course);
        self::validate_context($context);

        // Ambil data mahasiswa yang enroll di course ini
        $students = helper::get_students_in_course($section->course);
        $studentids = array_keys($students);
        $totalstudents = count($studentids);
        if (empty($students)) {
            return [
                'section' => [
                    'id' => $section->id,
                    'name' => $section->name,
                    'courseid' => $section->course,
                    'modules' => []
                ],
            ];
        }

        // Ambil data modul aktif di section course ini
        $modules = $DB->get_records_select(
            'course_modules',
            'course = ? AND section = ? AND visible = 1 AND deletioninprogress = 0',
            [$section->course, $sectionid],
            null,
            'id, course, section'
        );

        if (empty($modules)) {
            return [
                'section' => [
                    'id' => $section->id,
                    'name' => $section->name,
                    'courseid' => $section->course,
                    'modules' => []
                ],
            ];
        }

        $cmids = array_keys($modules);
        list($cm_sql, $cm_params) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cmid');
        list($user_sql, $user_params) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'uid');

        $sql = "SELECT l.contextinstanceid AS cmid, COUNT(l.id) AS total_viewed, COUNT(DISTINCT l.userid) AS total_users
                FROM {logstore_standard_log} l
                WHERE l.courseid = :courseid
                    AND l.contextinstanceid $cm_sql
                    AND l.userid $user_sql
                    AND l.action = 'viewed'
                GROUP BY l.contextinstanceid";

        $params = array_merge(['courseid' => $section->course], $cm_params, $user_params);

        $logdata = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($modules as $cm) {
            $log = $logdata[$cm->id] ?? null;
            $result[] = [
                'cmid' => $cm->id,
                'total_viewed' => $log->total_viewed ?? 0,
                'users_viewed' => $log->total_users ?? 0,
                'total_users' => $totalstudents
            ];
        }
        return [
            'section' => [
                'id' => $section->id,
                'name' => $section->name,
                'courseid' => $section->course,
                'modules' => $result
            ],
        ];
    }

    /**
     * Struktur output.
     */
    public static function execute_returns()
    {
        return new external_single_structure([
            'section' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Section ID'),
                'name' => new external_value(PARAM_TEXT, 'Section name'),
                'courseid' => new external_value(PARAM_INT, 'Course ID'),
                'modules' => new external_multiple_structure(
                    new external_single_structure([
                        'cmid' => new external_value(PARAM_INT, 'Course module ID'),
                        'total_viewed' => new external_value(PARAM_INT, 'Jumlah mahasiswa yang membuka modul ini'),
                        'users_viewed' => new external_value(PARAM_INT, 'Jumlah mahasiswa yang membuka modul ini'),
                        'total_users' => new external_value(PARAM_INT, 'Jumlah mahasiswa yang enroll di course ini')
                    ])
                )
            ]),
        ]);
    }
}
