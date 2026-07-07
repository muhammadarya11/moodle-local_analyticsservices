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
                AND gi.gradetype != 0
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
        $grades_by_user = [];
        foreach ($grades_all as $g) {
            $grades_by_item[$g->itemid][$g->userid] = [
                'finalgrade' => $g->finalgrade,
                'usermodified' => $g->usermodified,
            ];
            $grades_by_user[$g->userid][$g->itemid] = $g->finalgrade;
        }
        $underperforming_activities = [];
        $totalstudents = count($students);
        foreach ($modules as $module) {
            if ($module->modname === 'assign') {
                $assign = $DB->get_record('assign', ['id' => $module->iteminstance], 'grade');
                if ($assign && $assign->grade == 0) {
                    continue;
                }
            } elseif ($module->modname === 'quiz') {
                $quiz = $DB->get_record('quiz', ['id' => $module->iteminstance], 'grade');
                if ($quiz && $quiz->grade == 0) {
                    continue;
                }
            } elseif ($module->modname === 'forum') {
                $forum = $DB->get_record('forum', ['id' => $module->iteminstance], 'assessed');
                if ($forum && $forum->assessed == 0) {
                    continue;
                }
            } elseif ($module->modname === 'workshop') {
                $workshop = $DB->get_record('workshop', ['id' => $module->iteminstance], 'grade, gradinggrade');
                if ($workshop && $workshop->grade == 0 && $workshop->gradinggrade == 0) {
                    continue;
                }
            } elseif ($module->modname === 'lesson') {
                $lesson = $DB->get_record('lesson', ['id' => $module->iteminstance], 'grade');
                if ($lesson && $lesson->grade == 0) {
                    continue;
                }
            }
            $participated_users = helper::get_participated_users($module, $grades_by_user);
            $students_submitted = 0;
            $students_competent = 0;
            $students_actually_graded = 0;
            $incompetent_students = [];
            foreach ($students as $student) {
                if (!empty($participated_users[$student->id])) {
                    $students_submitted++;
                    $grade = $grades_by_item[$module->gradeitemid][$student->id]['finalgrade'] ?? null;
                    if ($grade !== null) {
                        $students_actually_graded++;
                    }
                    if ($grade !== null && $grade >= $params['competency_grade_threshold']) {
                        $students_competent++;
                    } else {
                        $incompetent_students[] = [
                            'id' => (int)$student->id,
                            'firstname' => $student->firstname,
                            'lastname' => $student->lastname,
                            'email' => $student->email,
                        ];
                    }
                }
            }
            // Calculate competent percentage (based on students submitted)
            $competent_percentage = $students_submitted > 0 ? ($students_competent / $students_submitted) * 100 : 100;
            $students_graded = $students_competent + count($incompetent_students);
            // Check if this activity is underperforming (must have at least one student actually graded by the teacher)
            if ($students_submitted > 0 && $students_actually_graded > 0 && $competent_percentage <= $params['max_competent_percentage']) {
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
