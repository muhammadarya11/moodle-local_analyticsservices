<?php

namespace local_analyticsservices\external\course;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_multiple_structure;
use context_course;
use local_analyticsservices\helper;

defined('MOODLE_INTERNAL') || die();

class get_underperforming_course_activities extends external_api
{
    public static function execute_parameters()
    {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'competency_grade_threshold' => new external_value(PARAM_FLOAT, 'Minimum grade required to be considered competent', VALUE_DEFAULT, 80.0),
            'max_competent_percentage' => new external_value(PARAM_FLOAT, 'Maximum percentage of competent students for an activity to be considered underperforming', VALUE_DEFAULT, 50.0),
        ]);
    }

    public static function execute($courseid, $competency_grade_threshold, $max_competent_percentage)
    {
        global $DB;

        // Validate parameters and context
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'competency_grade_threshold' => $competency_grade_threshold,
            'max_competent_percentage' => $max_competent_percentage,
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Get Course Data
        $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, fullname, shortname', MUST_EXIST);

        // Get all students in the course
        $students = helper::get_students_in_course($courseid);

        if (empty($students)) {
            return [
                'course' => [
                    'id' => $course->id,
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname,
                    'activities' => [],
                ]
            ];
        }

        // Get all graded activities (modules) in the course
        $modules = $DB->get_records_sql(
            "SELECT DISTINCT
                gi.id AS gradeitemid,
                gi.itemname AS name,
                gi.itemmodule AS modname,
                gi.iteminstance,
                cm.id AS cmid
            FROM {grade_items} gi
            JOIN {modules} m
                ON m.name = gi.itemmodule
            JOIN {course_modules} cm
                ON cm.module = m.id
                AND cm.instance = gi.iteminstance
                AND cm.course = gi.courseid
                AND cm.visible = 1
            WHERE gi.courseid = :courseid
                AND cm.deletioninprogress = 0
                AND gi.itemtype = 'mod'
                AND gi.itemmodule IS NOT NULL
            ORDER BY gi.itemname",
            ['courseid' => $courseid]
        );

        if (empty($modules)) {
            return [
                'course' => [
                    'id' => $course->id,
                    'fullname' => $course->fullname,
                    'shortname' => $course->shortname,
                    'activities' => [],
                ]
            ];
        }

        // Get all grades for all grade items at once
        list($gradeitem_sql, $gradeitem_params) = $DB->get_in_or_equal(
            array_column($modules, 'gradeitemid'),
            SQL_PARAMS_NAMED,
            'giid'
        );

        $grades_all = $DB->get_records_sql(
            "SELECT id, userid, itemid, usermodified, finalgrade
            FROM {grade_grades}
            WHERE itemid $gradeitem_sql",
            $gradeitem_params
        );

        // Index grades by item and user for fast access
        $grades_by_item = [];
        foreach ($grades_all as $g) {
            $grades_by_item[$g->itemid][$g->userid] = [
                'finalgrade' => $g->finalgrade,
                'usermodified' => $g->usermodified,
            ];
        }

        $underperforming_activities = [];
        $totalstudents = count($students);

        // Process each activity
        foreach ($modules as $module) {
            $students_submitted = 0;
            $students_competent = 0;
            $incompetent_students = [];

            // Check each student's grade for this activity
            foreach ($students as $student) {
                $grade = $grades_by_item[$module->gradeitemid][$student->id]['finalgrade'] ?? null;
                $submittedbymodifier = $grades_by_item[$module->gradeitemid][$student->id]['usermodified'] ?? null;

                if ($submittedbymodifier != null) {
                    $students_submitted++;
                }
                // Count submitted (has a grade entry)
                if ($grade != null) {
                    // Check if competent (grade >= threshold)
                    if ($grade >= $params['competency_grade_threshold']) {
                        $students_competent++;
                    } else {
                        // This student is incompetent
                        $incompetent_students[] = [
                            'id' => (int)$student->id,
                            'firstname' => $student->firstname,
                            'lastname' => $student->lastname,
                            'email' => $student->email,
                        ];
                    }
                } else {
                    // No grade means incompetent
                    $incompetent_students[] = [
                        'id' => (int)$student->id,
                        'firstname' => $student->firstname,
                        'lastname' => $student->lastname,
                        'email' => $student->email,
                    ];
                }
            }

            // Calculate competent percentage (based on total students)
            $competent_percentage = $totalstudents > 0 ? ($students_competent / $totalstudents) * 100 : 0;

            // Check if this activity is underperforming
            if ($competent_percentage <= $params['max_competent_percentage']) {
                $underperforming_activities[] = [
                    'id' => (int)$module->cmid,
                    'name' => $module->name,
                    'module' => $module->modname,
                    'total_students' => $totalstudents,
                    'students_submitted' => $students_submitted,
                    'students_competent' => $students_competent,
                    'students_incompetent' => $incompetent_students,
                ];
            }
        }
        return [
            'course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'activities' => $underperforming_activities
            ]
        ];
    }

    public static function execute_returns()
    {
        return new external_single_structure([
            'course' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course ID'),
                'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
                'activities' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Activity ID (course module ID)'),
                        'name' => new external_value(PARAM_TEXT, 'Activity name'),
                        'module' => new external_value(PARAM_TEXT, 'Module type (e.g., assign, quiz)'),
                        'total_students' => new external_value(PARAM_INT, 'Total number of students in course'),
                        'students_submitted' => new external_value(PARAM_INT, 'Number of students who submitted'),
                        'students_competent' => new external_value(PARAM_INT, 'Number of competent students'),
                        'students_incompetent' => new external_multiple_structure(
                            new external_single_structure([
                                'id' => new external_value(PARAM_INT, 'Student ID'),
                                'firstname' => new external_value(PARAM_TEXT, 'Student first name'),
                                'lastname' => new external_value(PARAM_TEXT, 'Student last name'),
                                'email' => new external_value(PARAM_TEXT, 'Student email'),
                            ]),
                            'List of incompetent students'
                        ),
                    ]),
                    'List of underperforming activities'
                ),
            ]),
        ]);
    }
}
