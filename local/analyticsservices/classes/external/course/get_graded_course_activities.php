<?php

namespace local_analyticsservices\external\course;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;

use local_analyticsservices\helper;

defined('MOODLE_INTERNAL') || die();

class get_graded_course_activities extends external_api
{

    public static function execute_parameters()
    {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * Get data aktivitas yang sudah dikerjakan dan dinilai.
     */
    public static function execute($courseid)
    {
        global $DB;

        self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid
        ]);

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
                'course' => [
                    'id' => $courseid,
                    'name' => $course->fullname,
                    'shortname' => $course->shortname,
                    'activities' => []
                ]
            ];
        }

        // Ambil semua aktivitas yang memiliki grade item (modul apapun).
        $sql = "SELECT cm.id AS cmid,
                    m.name AS itemmodule,
                    cm.instance AS iteminstance,
                    COALESCE(gi.id, 0) AS gradeitemid,
                    gi.itemname,
                    gi.gradetype,
                    COUNT(DISTINCT CASE WHEN g.finalgrade IS NOT NULL THEN g.userid END) AS gradedcount 
                FROM {course_modules} cm
                JOIN {modules} m ON m.name = m.name AND cm.module = m.id
                LEFT JOIN {grade_items} gi ON gi.itemmodule = m.name AND gi.iteminstance = cm.instance AND gi.courseid = cm.course
                LEFT JOIN {grade_grades} g ON g.itemid = gi.id
                WHERE cm.course = :courseid
                  AND cm.deletioninprogress = 0
                  AND m.name IN ('assign', 'quiz', 'forum', 'workshop', 'lesson', 'scorm')
                GROUP BY cm.id, m.name, cm.instance, gi.id, gi.itemname, gi.gradetype";

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        $gradeitemids = [];
        foreach ($records as $r) {
            if ($r->gradeitemid) {
                $gradeitemids[] = $r->gradeitemid;
            }
        }
        $grades_by_user = [];
        if (!empty($gradeitemids)) {
            list($gradeitem_sql, $gradeitem_params) = $DB->get_in_or_equal($gradeitemids, SQL_PARAMS_NAMED, 'giid');
            $grades_all = $DB->get_records_sql("SELECT id, userid, itemid, finalgrade FROM {grade_grades} WHERE itemid $gradeitem_sql", $gradeitem_params);
            foreach ($grades_all as $g) {
                $grades_by_user[$g->userid][$g->itemid] = $g->finalgrade;
            }
        }

        $results = [];
        foreach ($records as $r) {
            $name = $r->itemname;
            $has_grading = false;

            if ($r->gradeitemid && $r->gradetype != 0) {
                $has_grading = true;
            }

            if ($r->itemmodule === 'assign') {
                $assign = $DB->get_record('assign', ['id' => $r->iteminstance], 'name, grade');
                if ($assign) {
                    $name = $assign->name;
                    if ($assign->grade == 0) {
                        $has_grading = false;
                    }
                }
            } elseif ($r->itemmodule === 'quiz') {
                $quiz = $DB->get_record('quiz', ['id' => $r->iteminstance], 'name');
                if ($quiz) {
                    $name = $quiz->name;
                }
            } elseif ($r->itemmodule === 'forum') {
                $forum = $DB->get_record('forum', ['id' => $r->iteminstance], 'name');
                if ($forum) {
                    $name = $forum->name;
                }
            }

            if (empty($name)) {
                $name = 'Unnamed activity';
            }

            $r->modname = $r->itemmodule;
            $participated_users = helper::get_participated_users($r, $grades_by_user);
            $students_submitted = count($participated_users);

            $results[] = [
                'id' => (int)$r->iteminstance,
                'name' => $name,
                'module' => $r->itemmodule,
                'total_students' => $totalstudents,
                'students_submitted' => (int)$students_submitted,
                'students_graded' => (int)$r->gradedcount,
                'has_grading' => $has_grading
            ];
        }

        return [
            'course' => [
                'id' => $courseid,
                'name' => $course->fullname,
                'shortname' => $course->shortname,
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
            'course' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course ID'),
                'name' => new external_value(PARAM_TEXT, 'Nama course'),
                'shortname' => new external_value(PARAM_TEXT, 'Shortname course'),
                'activities' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Activity instance ID'),
                        'name' => new external_value(PARAM_TEXT, 'Activity name'),
                        'module' => new external_value(PARAM_TEXT, 'Module name'),
                        'total_students' => new external_value(PARAM_INT, 'Total number of students in course'),
                        'students_submitted' => new external_value(PARAM_INT, 'Number of students who have submitted'),
                        'students_graded' => new external_value(PARAM_INT, 'Number of students who have been graded'),
                        'has_grading' => new external_value(PARAM_BOOL, 'Whether the activity has grading')
                    ]),
                    'List of graded activities in the course'
                )
            ])
        ]);
    }
}
