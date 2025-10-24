<?php

namespace local_analyticsservices\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;

use local_analyticsservices\helper;

defined('MOODLE_INTERNAL') || die();

class get_students_competent_percentage extends external_api
{
    public static function execute_parameters()
    {
        return new external_function_parameters([
            'courseid'        => new external_value(PARAM_INT, 'Course ID'),
            'threshold'       => new external_value(PARAM_FLOAT, 'Minimum grade per activity to be considered competent', VALUE_DEFAULT, 80.0),
            'minactivityrate' => new external_value(PARAM_FLOAT, 'Minimum percentage of activities passed to be competent', VALUE_DEFAULT, 80.0),
        ]);
    }

    public static function execute($courseid, $threshold, $minactivityrate)
    {
        global $DB;

        // Validate parameters and context course
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'threshold' => $threshold,
            'minactivityrate' => $minactivityrate
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Get Course Data
        $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, fullname, shortname', MUST_EXIST);

        // Get student data
        $students = helper::get_students_in_course($courseid);
        if (empty($students)) {
            return ['course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'total_students' => 0,
                'competent_students' => 0,
                'incompetent_students' => 0,
                'competent_percentage' => 0,
                'incompetent_percentage' => 0
            ]];
        }

        // Hitung kompetensi per mahasiswa
        $competentcount = 0;
        $totalstudents = count($students);

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
            return ['course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'total_students' => $totalstudents,
                'competent_students' => $totalstudents,
                'incompetent_students' => 0,
                'competent_percentage' => 100,
                'incompetent_percentage' => 0
            ]];
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

        // Index grades per user agar cepat diakses
        $grades_by_user = [];
        foreach ($grades_all as $g) {
            $grades_by_user[$g->userid][$g->itemid] = $g->finalgrade;
        }

        // Total grade items yang bisa dinilai
        $totalactivities = count($modules);

        foreach ($students as $student) {
            $passedActivities = 0;

            foreach ($modules as $module) {
                $grade = $grades_by_user[$student->id][$module->gradeitemid] ?? null;
                if ($grade !== null && $grade >= $threshold) {
                    $passedActivities++;
                }
            }

            $activityRate = $totalactivities > 0 ? ($passedActivities / $totalactivities) * 100 : 0;
            if ($activityRate >= $minactivityrate) {
                $competentcount++;
            }
        }

        $incompetentcount = $totalstudents - $competentcount;
        $competentPercentage = $totalstudents > 0 ? round(($competentcount / $totalstudents) * 100, 2) : 0;
        $incompetentPercentage = 100 - $competentPercentage;

        return [
            'course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'total_students' => $totalstudents,
                'competent_students' => $competentcount,
                'incompetent_students' => $incompetentcount,
                'competent_percentage' => $competentPercentage,
                'incompetent_percentage' => $incompetentPercentage,
            ]
        ];
    }

    public static function execute_returns()
    {
        return new external_single_structure([
            'course' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course ID'),
                'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                'shortname' => new external_value(PARAM_TEXT, 'Short name'),
                'total_students' => new external_value(PARAM_INT, 'Total students'),
                'competent_students' => new external_value(PARAM_INT, 'Total competent students'),
                'incompetent_students' => new external_value(PARAM_INT, 'Total incompetent students'),
                'competent_percentage' => new external_value(PARAM_FLOAT, 'Percentage of competent students'),
                'incompetent_percentage' => new external_value(PARAM_FLOAT, 'Percentage of incompetent students'),
            ])
        ]);
    }
}
