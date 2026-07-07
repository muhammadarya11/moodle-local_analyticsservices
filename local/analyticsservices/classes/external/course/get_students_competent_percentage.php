<?php

namespace local_analyticsservices\external\course;

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
            'grade_threshold'       => new external_value(PARAM_FLOAT, 'Minimum average grade (0-100) to be considered competent', VALUE_DEFAULT, 50.0),
            'competent_activity_rate' => new external_value(PARAM_FLOAT, 'Minimum percentage of graded activities completed to be competent (0-100)', VALUE_DEFAULT, 80.0),
            'inactive_activity_rate' => new external_value(PARAM_FLOAT, 'Maximum percentage of graded activities completed to be considered a ghost student (0-100)', VALUE_DEFAULT, 20.0),
        ]);
    }

    public static function execute($courseid, $grade_threshold, $competent_activity_rate, $inactive_activity_rate)
    {
        global $DB;

        // Validate parameters and context course
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'grade_threshold' => $grade_threshold,
            'competent_activity_rate' => $competent_activity_rate,
            'inactive_activity_rate' => $inactive_activity_rate,
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
                'students' => [
                    'total' => 0,
                    'competent' => 0,
                    'incompetent' => 0,
                    'inactive' => 0,
                ],
                'total_activities' => 0,
            ]];
        }

        $totalstudents = count($students);

        // Ambil kegiatan berpenilaian (graded activities)
        // Ketiga kategori (kompeten, belum kompeten, hantu) semuanya
        // berbasis kegiatan berpenilaian saja.
        $graded_modules = $DB->get_records_sql(
            "SELECT DISTINCT
                gi.id AS gradeitemid,
                gi.itemname AS name,
                gi.itemmodule AS modname,
                gi.grademax,
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

        // Jika tidak ada kegiatan berpenilaian, anggap semua mahasiswa kompeten
        if (empty($graded_modules)) {
            return ['course' => [
                'id'               => $course->id,
                'fullname'         => $course->fullname,
                'shortname'        => $course->shortname,
                'students'         => [
                    'total'       => $totalstudents,
                    'competent'   => $totalstudents,
                    'incompetent' => 0,
                    'inactive'    => 0,
                ],
                'total_activities' => 0,
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
        
        // Kategorisasi setiap mahasiswa
        // Basis semua kategori: kegiatan berpenilaian
        // HANTU        : mengerjakan/mengumpulkan ≤ inactive_activity_rate% dari total kegiatan berpenilaian
        // KOMPETEN     : mengerjakan ≥ competent_activity_rate% DAN rata-rata nilai (0–100) ≥ grade_threshold
        // BELUM KOMPETEN: bukan hantu, belum memenuhi syarat kompeten
        
        $competentcount  = 0;
        $incompetentcount = 0;
        $inactivecount   = 0;

        foreach ($students as $student) {
            $uid        = $student->id;
            $userGrades = $grades_by_user[$uid] ?? [];

            $gradedParticipated = 0; // Jumlah kegiatan yang sudah dikerjakan/dikumpulkan
            $gradedGraded       = 0; // Jumlah kegiatan yang sudah dinilai (untuk rata-rata)
            $gradeSum           = 0.0;

            foreach ($graded_modules as $module) {
                // Partisipasi: sudah mengerjakan/submit ATAU sudah diberi nilai oleh dosen
                if (!empty($participated_by_module[$module->gradeitemid][$uid])) {
                    $gradedParticipated++;
                }

                // Rata-rata nilai hanya dihitung dari kegiatan yang sudah dinilai (finalgrade tidak NULL)
                if (isset($userGrades[$module->gradeitemid]) && $userGrades[$module->gradeitemid] !== null) {
                    $grade = $userGrades[$module->gradeitemid];
                    $normalizedGrade = $module->grademax > 0
                        ? ($grade / $module->grademax) * 100.0
                        : 0.0;
                    $gradeSum += $normalizedGrade;
                    $gradedGraded++;
                }
            }

            // Persentase kegiatan berpenilaian yang sudah dikerjakan/dikumpulkan
            $gradedParticipationRate = ($gradedParticipated / $totalGradedActivities) * 100;

            // Cek Hantu
            // Mahasiswa yang hanya mengerjakan <= inactive_activity_rate% kegiatan berpenilaian
            if ($gradedParticipationRate <= $params['inactive_activity_rate']) {
                $inactivecount++;
                continue;
            }

            // Rata-rata nilai ternormalisasi (hanya dari yang sudah dinilai)
            $averageGrade = $gradedGraded > 0
                ? ($gradeSum / $gradedGraded)
                : 0.0;

            // Cek Kompeten
            // Mengerjakan >= competent_activity_rate% DAN rata-rata nilai >= grade_threshold
            if (
                $gradedParticipationRate >= $params['competent_activity_rate'] &&
                $averageGrade >= $params['grade_threshold']
            ) {
                $competentcount++;
            } else {
                // Belum Kompeten: aktif tetapi belum memenuhi syarat kompeten
                $incompetentcount++;
            }
        }

        return [
            'course' => [
                'id' => $course->id,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'students' => [
                    'total' => $totalstudents,
                    'competent' => $competentcount,
                    'incompetent' => $incompetentcount,
                    'inactive' => $inactivecount,
                ],
                'total_activities' => $totalGradedActivities,
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
                'students' => new external_single_structure([
                    'total' => new external_value(PARAM_INT, 'Total number of students'),
                    'competent' => new external_value(PARAM_INT, 'Number of competent students'),
                    'incompetent' => new external_value(PARAM_INT, 'Number of incompetent (not yet competent) students'),
                    'inactive'    => new external_value(PARAM_INT, 'Number of ghost/inactive students'),
                ]),
                'total_activities' => new external_value(PARAM_INT, 'Total number of graded activities'),
            ])
        ]);
    }
}
