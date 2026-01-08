<?php

namespace local_analyticsservices\external\section;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;

use local_analyticsservices\helper;

defined('MOODLE_INTERNAL') || die();

class get_students_competent_percentage_by_section extends external_api
{
    public static function execute_parameters()
    {
        return new external_function_parameters([
            'sectionid'               => new external_value(PARAM_INT, 'Section ID'),
            'grade_threshold'         => new external_value(PARAM_FLOAT, 'Minimum grade per activity to be considered competent', VALUE_DEFAULT, 80.0),
            'competent_activity_rate' => new external_value(PARAM_FLOAT, 'Minimum percentage of activities passed to be competent', VALUE_DEFAULT, 80.0),
            'inactive_activity_rate'  => new external_value(PARAM_FLOAT, 'Maximum percentage of activities participated to be considered an inactive student', VALUE_DEFAULT, 20.0),
        ]);
    }

    public static function execute($sectionid, $grade_threshold, $competent_activity_rate, $inactive_activity_rate)
    {
        global $DB;

        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), [
            'sectionid' => $sectionid,
            'grade_threshold' => $grade_threshold,
            'competent_activity_rate' => $competent_activity_rate,
            'inactive_activity_rate' => $inactive_activity_rate
        ]);

        // Get section data and course
        $section = $DB->get_record('course_sections', ['id' => $params['sectionid']], 'id, course, name, section', MUST_EXIST);
        $courseid = $section->course;

        // Validate context
        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Get Course Data
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);

        // Get student data
        $students = helper::get_students_in_course($courseid);
        if (empty($students)) {
            return [
                'section' => [
                    'id' => $section->id,
                    'name' => $section->name,
                    'sectionnumber' => $section->section,
                    'courseid' => $course->id,
                    'coursename' => $course->fullname,
                    'students' => [
                        'total' => 0,
                        'competent' => 0,
                        'incompetent' => 0,
                        'inactive' => 0,
                    ],
                ],
            ];
        }

        // Hitung kompetensi per mahasiswa
        $competentcount = 0;
        $incompetentcount = 0;
        $inactivecount = 0;
        $totalstudents = count($students);

        // List modules atau aktivitas yang bisa dinilai dalam section ini
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
                AND cm.section = :sectionid
            WHERE gi.courseid = :courseid
                AND cm.deletioninprogress = 0
                AND gi.itemtype = 'mod'
                AND gi.itemmodule IS NOT NULL",
            [
                'courseid' => $courseid,
                'sectionid' => $params['sectionid']
            ]
        );

        // Jika tidak ada modul yang bisa dinilai, anggap semua mahasiswa kompeten
        if (empty($modules)) {
            return [
                'section' => [
                    'id' => $section->id,
                    'name' => $section->name,
                    'sectionnumber' => $section->section,
                    'courseid' => $course->id,
                    'coursename' => $course->fullname,
                    'students' => [
                        'total' => $totalstudents,
                        'competent' => $totalstudents,
                        'incompetent' => 0,
                        'inactive' => 0,
                    ],
                ],
            ];
        }

        // Ambil semua grade sekaligus untuk semua grade item di section ini
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

        // Index grades per user agar cepat diakses
        $grades_by_user = [];
        foreach ($grades_all as $g) {
            $grades_by_user[$g->userid][$g->itemid] = $g->finalgrade;
        }

        // Total grade items yang bisa dinilai dalam section ini
        $totalactivities = count($modules);

        foreach ($students as $student) {
            $passedActivities = 0;
            $participatedActivities = 0;

            foreach ($modules as $module) {
                $grade = $grades_by_user[$student->id][$module->gradeitemid] ?? null;

                // Hitung partisipasi (mahasiswa yang memiliki grade entry, apapun nilainya)
                if ($grade !== null) {
                    $participatedActivities++;

                    // Hitung aktivitas yang lulus (grade >= threshold)
                    if ($grade >= $grade_threshold) {
                        $passedActivities++;
                    }
                }
            }

            // Hitung participation rate (berapa persen aktivitas yang diikuti)
            $participationRate = $totalactivities > 0 ? ($participatedActivities / $totalactivities) * 100 : 0;

            // Hitung activity rate (berapa persen aktivitas yang lulus)
            $activityRate = $totalactivities > 0 ? ($passedActivities / $totalactivities) * 100 : 0;

            // Kategorisasi mahasiswa
            if ($participationRate <= $inactive_activity_rate) {
                $inactivecount++;
            } else if ($activityRate >= $competent_activity_rate) {
                $competentcount++;
            } else {
                $incompetentcount++;
            }
        }

        return [
            'section' => [
                'id' => $section->id,
                'name' => $section->name,
                'sectionnumber' => $section->section,
                'courseid' => $course->id,
                'coursename' => $course->fullname,
                'students' => [
                    'total' => $totalstudents,
                    'competent' => $competentcount,
                    'incompetent' => $incompetentcount,
                    'inactive' => $inactivecount,
                ],
            ],
        ];
    }

    public static function execute_returns()
    {
        return new external_single_structure([
            'section' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Section ID'),
                'name' => new external_value(PARAM_TEXT, 'Section name'),
                'sectionnumber' => new external_value(PARAM_INT, 'Section number'),
                'courseid' => new external_value(PARAM_INT, 'Course ID'),
                'coursename' => new external_value(PARAM_TEXT, 'Course name'),
                'students' => new external_single_structure([
                    'total' => new external_value(PARAM_INT, 'Total number of students'),
                    'competent' => new external_value(PARAM_INT, 'Number of competent students'),
                    'incompetent' => new external_value(PARAM_INT, 'Number of incompetent students'),
                    'inactive' => new external_value(PARAM_INT, 'Number of inactive students'),
                ]),
            ]),
        ]);
    }
}
