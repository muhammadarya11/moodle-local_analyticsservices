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

class get_graded_course_activities_by_section extends external_api
{

    public static function execute_parameters()
    {
        return new external_function_parameters([
            'sectionid' => new external_value(PARAM_INT, 'Section ID'),
        ]);
    }

    /**
     * Get data aktivitas yang sudah dikerjakan dan dinilai berdasarkan section.
     */
    public static function execute($sectionid)
    {
        global $DB;

        self::validate_parameters(self::execute_parameters(), [
            'sectionid' => $sectionid
        ]);

        // Get section data and course ID
        $section = $DB->get_record('course_sections', ['id' => $sectionid], 'id, course, name, section', MUST_EXIST);
        $courseid = $section->course;

        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Get Course Data
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);

        // Ambil data mahasiswa yang enroll di course ini.
        $students = helper::get_students_in_course($courseid);
        $totalstudents = count($students);

        // Kalau tidak ada mahasiswa, return kosong.
        if ($totalstudents == 0) {
            return [
                'section' => [
                    'id' => $sectionid,
                    'name' => $section->name ?? 'Section ' . $section->section,
                    'section_number' => (int)$section->section,
                    'courseid' => $courseid,
                    'coursename' => $course->fullname,
                    'courseshortname' => $course->shortname,
                    'activities' => []
                ]
            ];
        }

        // Ambil semua aktivitas yang memiliki grade item (modul apapun) di section ini.
        $sql = "SELECT gi.id AS gradeitemid,
                    gi.itemname,
                    gi.itemmodule,
                    gi.iteminstance,
                    COUNT(DISTINCT CASE WHEN g.usermodified IS NOT NULL THEN g.userid END) AS students_submitted,
                    COUNT(DISTINCT CASE WHEN g.finalgrade IS NOT NULL THEN g.userid END) AS gradedcount 
                FROM {grade_items} gi
                JOIN {modules} m ON m.name = gi.itemmodule
                JOIN {course_modules} cm ON cm.module = m.id
                    AND cm.instance = gi.iteminstance
                    AND cm.deletioninprogress = 0
                    AND cm.section = :sectionid
                LEFT JOIN {grade_grades} g ON g.itemid = gi.id
                WHERE gi.courseid = :courseid
                    AND gi.itemtype = 'mod'
                GROUP BY gi.id, gi.itemname, gi.itemmodule, gi.iteminstance
                ORDER BY gi.itemmodule, gi.itemname";

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid, 'sectionid' => $sectionid]);

        $results = [];
        foreach ($records as $r) {
            $results[] = [
                'id' => (int)$r->iteminstance,
                'name' => $r->itemname ?? 'Unnamed activity',
                'module' => $r->itemmodule ?? 'unknown',
                'total_students' => $totalstudents,
                'students_submitted' => (int)$r->students_submitted,
                'students_graded' => (int)$r->gradedcount
            ];
        }

        return [
            'section' => [
                'id' => $sectionid,
                'name' => $section->name ?? 'Section ' . $section->section,
                'section_number' => (int)$section->section,
                'courseid' => $courseid,
                'coursename' => $course->fullname,
                'courseshortname' => $course->shortname,
                'activities' => $results
            ]
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
                'name' => new external_value(PARAM_TEXT, 'Nama section'),
                'section_number' => new external_value(PARAM_INT, 'Section number'),
                'courseid' => new external_value(PARAM_INT, 'Course ID'),
                'coursename' => new external_value(PARAM_TEXT, 'Nama course'),
                'courseshortname' => new external_value(PARAM_TEXT, 'Shortname course'),
                'activities' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Activity instance ID'),
                        'name' => new external_value(PARAM_TEXT, 'Activity name'),
                        'module' => new external_value(PARAM_TEXT, 'Module name'),
                        'total_students' => new external_value(PARAM_INT, 'Total number of students in course'),
                        'students_submitted' => new external_value(PARAM_INT, 'Number of students who have submitted'),
                        'students_graded' => new external_value(PARAM_INT, 'Number of students who have been graded'),
                    ]),
                    'List of graded activities in the section'
                )
            ])
        ]);
    }
}
