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
            'inactive_activity_rate' => new external_value(PARAM_FLOAT, 'Maximum percentage of graded activities completed to be considered a ghost student (0-100)', VALUE_DEFAULT, 20.0),
        ]);
    }

    public static function execute($courseid, $inactive_activity_rate)
    {
        global $DB;

        // Validate parameters and context course
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'inactive_activity_rate' => $inactive_activity_rate,
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Get Course Data
        $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, fullname, shortname', MUST_EXIST);

        $students = helper::get_students_in_course($courseid);

        if (empty($students)) {
            return ['course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'students' => [],
            ]];
        }

        // Ambil last access mahasiswa secara terpisah
        $studentIds = array_keys($students);
        list($inSql, $inParams) = $DB->get_in_or_equal($studentIds, SQL_PARAMS_NAMED, 'uid');
        $inParams['courseid'] = $courseid;
        $lastaccesses = $DB->get_records_sql(
            "SELECT userid, timeaccess FROM {user_lastaccess} WHERE courseid = :courseid AND userid $inSql",
            $inParams
        );

        // Ambil kegiatan berpenilaian
        // Hantu ditentukan dari persentase kegiatan berpenilaian yang dikerjakan.
        $graded_modules = $DB->get_records_sql(
            "SELECT DISTINCT
                gi.id AS gradeitemid,
                gi.itemmodule AS modname,
                cm.instance AS iteminstance,
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
                AND gi.grademax > 0
                AND (gi.itemmodule != 'assign' OR (SELECT grade FROM {assign} WHERE id = gi.iteminstance) != 0)",
            ['courseid' => $courseid]
        );
        $totalGradedActivities = count($graded_modules);

        // Jika tidak ada kegiatan berpenilaian, tidak ada yang bisa dikategorikan hantu
        if (empty($graded_modules)) {
            return ['course' => [
                'id'        => $course->id,
                'fullname'  => $course->fullname,
                'shortname' => $course->shortname,
                'students'  => [],
            ]];
        }

        // Ambil semua grade untuk kegiatan berpenilaian
        list($gradeitem_sql, $gradeitem_params) = $DB->get_in_or_equal(
            array_column($graded_modules, 'gradeitemid'),
            SQL_PARAMS_NAMED,
            'giid'
        );

        $grades_all = $DB->get_records_sql(
            "SELECT id, userid, itemid, finalgrade
            FROM {grade_grades}
            WHERE itemid $gradeitem_sql",
            $gradeitem_params
        );

        // Index: [userid][gradeitemid] = finalgrade
        $grades_by_user = [];
        foreach ($grades_all as $g) {
            $grades_by_user[$g->userid][$g->itemid] = $g->finalgrade;
        }

        $participated_by_module = [];
        foreach ($graded_modules as $module) {
            $participated_by_module[$module->gradeitemid] = helper::get_participated_users($module, $grades_by_user);
        }
        
        // Filter mahasiswa yang masuk kategori hantu
        // Hantu: mengerjakan/mengumpulkan <= inactive_activity_rate% dari total kegiatan berpenilaian
        $inactivestudents = [];

        foreach ($students as $student) {
            $uid        = $student->id;

            $gradedParticipated = 0;
            foreach ($graded_modules as $module) {
                // Partisipasi: sudah mengerjakan/submit ATAU sudah diberi nilai oleh dosen
                if (!empty($participated_by_module[$module->gradeitemid][$uid])) {
                    $gradedParticipated++;
                }
            }

            $participationRate = ($gradedParticipated / $totalGradedActivities) * 100;

            if ($participationRate <= $params['inactive_activity_rate']) {
                $inactivestudents[] = [
                    'id' => $student->id,
                    'firstname' => $student->firstname,
                    'lastname' => $student->lastname,
                    'email' => $student->email,
                    'lastaccess' => $lastaccesses[$uid]->timeaccess ?? null,
                    'participatedactivities' => $gradedParticipated,
                    'totalactivities' => $totalGradedActivities,
                    'participationrate' => round($participationRate, 2),
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
                'id'        => new external_value(PARAM_INT, 'Course ID'),
                'fullname'  => new external_value(PARAM_TEXT, 'Course full name'),
                'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
                'students'  => new external_multiple_structure(
                    new external_single_structure([
                        'id' => new external_value(PARAM_INT, 'Student ID'),
                        'firstname' => new external_value(PARAM_TEXT, 'Student first name'),
                        'lastname' => new external_value(PARAM_TEXT, 'Student last name'),
                        'email' => new external_value(PARAM_TEXT, 'Student email'),
                        'lastaccess' => new external_value(PARAM_INT, 'Last access timestamp', VALUE_OPTIONAL),
                        'participatedactivities' => new external_value(PARAM_INT, 'Number of graded activities completed'),
                        'totalactivities' => new external_value(PARAM_INT, 'Total number of graded activities in course'),
                        'participationrate' => new external_value(PARAM_FLOAT, 'Participation rate in graded activities (%)'),
                    ]),
                    'List of ghost/inactive students'
                ),
            ]),
        ]);
    }
}
