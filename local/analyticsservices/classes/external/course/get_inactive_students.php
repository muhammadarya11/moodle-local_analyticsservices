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

class get_inactive_students extends external_api
{
    public static function execute_parameters()
    {
        return new external_function_parameters([
            'courseid'        => new external_value(PARAM_INT, 'Course ID'),
            'inactive_activity_rate' => new external_value(PARAM_FLOAT, 'Maximum percentage of activities participated to be considered an inactive student', VALUE_DEFAULT, 20.0),
        ]);
    }

    public static function execute($courseid, $inactive_activity_rate)
    {
        global $DB;

        // Validate parameters and context course
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'inactive_activity_rate' => $inactive_activity_rate
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Get Course Data
        $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, fullname, shortname', MUST_EXIST);

        $sql = "SELECT u.id AS id, u.firstname, u.lastname, u.email, ula.timeaccess AS lastaccess
                FROM {user} u
                JOIN {user_enrolments} ue ON ue.userid = u.id
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {context} ctx ON ctx.instanceid = e.courseid
                JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = u.id
                LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
                WHERE e.courseid = :courseid
                    AND ctx.contextlevel = :contextcourse
                    AND ra.roleid = 5
                    AND ue.status = 0
                    AND e.status = 0
                    AND u.suspended = 0
                ORDER BY u.lastname, u.firstname";

        // Get student data
        $students = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'contextcourse' => $context->contextlevel
        ]);

        if (empty($students)) {
            return ['course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'students' => [],
            ]];
        }

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
            WHERE gi.courseid = :courseid
                AND cm.deletioninprogress = 0
                AND gi.itemtype = 'mod'
                AND gi.itemmodule IS NOT NULL",
            ['courseid' => $courseid]
        );

        // Jika tidak ada modul yang bisa dinilai, anggap semua mahasiswa kompeten
        if (empty($modules)) {
            return ['course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'students' => $students,
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

        $totalactivities = count($modules);
        $inactivestudents = [];

        foreach ($students as $student) {
            $participatedActivities = 0;

            foreach ($modules as $module) {
                $grade = $grades_by_user[$student->id][$module->gradeitemid] ?? null;
                // Hitung partisipasi (mahasiswa yang memiliki grade entry, apapun nilainya)
                if ($grade !== null) {
                    $participatedActivities++;
                }
            }

            // Hitung participation rate (berapa persen aktivitas yang diikuti)
            $participationRate = $totalactivities > 0 ? ($participatedActivities / $totalactivities) * 100 : 0;

            // Kategorisasi mahasiswa
            if ($participationRate <= $inactive_activity_rate) {
                $inactivestudents[] = [
                    'id' => $student->id,
                    'firstname' => $student->firstname,
                    'lastname' => $student->lastname,
                    'email' => $student->email,
                    'lastaccess' => $student->lastaccess,
                    'participatedactivities' => $participatedActivities,
                    'totalactivities' => $totalactivities,
                    'participationrate' => $participationRate,
                ];
            }
        }

        return [
            'course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'students' => $inactivestudents,
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
                'students' => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Student ID'),
                        'firstname' => new external_value(PARAM_TEXT, 'Student first name'),
                        'lastname' => new external_value(PARAM_TEXT, 'Student last name'),
                        'email' => new external_value(PARAM_TEXT, 'Student email'),
                        'lastaccess' => new external_value(PARAM_INT, 'Last access timestamp', VALUE_OPTIONAL),
                        'participatedactivities' => new external_value(PARAM_INT, 'Number of activities participated'),
                        'totalactivities' => new external_value(PARAM_INT, 'Total number of activities in course'),
                        'participationrate' => new external_value(PARAM_FLOAT, 'Participation rate percentage'),
                    ]),
                    'List of inactive students'
                ),
            ]),
        ]);
    }
}
