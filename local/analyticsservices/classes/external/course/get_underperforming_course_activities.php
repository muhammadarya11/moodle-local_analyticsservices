<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * External function to get underperforming course activities.
 *
 * @package   local_analyticsservices
 * @copyright 2026, Arya Kusuma <muhammadaryakusuma@gmail.com>
 * @copyright 2026, Safiyyah Yahya <safiyyahyahya163@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_analyticsservices\external\course;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_multiple_structure;
use context_course;
use local_analyticsservices\helper;

/**
 * Class get_underperforming_course_activities.
 */
class get_underperforming_course_activities extends external_api {

    /**
     * Define the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid'                   => new external_value(PARAM_INT, 'Course ID'),
            'competency_grade_threshold' => new external_value(
                PARAM_FLOAT,
                'Minimum grade required to be considered competent.',
                VALUE_DEFAULT,
                80.0
            ),
            'max_competent_percentage'   => new external_value(
                PARAM_FLOAT,
                'Maximum percentage of competent students for an activity to be considered underperforming.',
                VALUE_DEFAULT,
                50.0
            ),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $courseid Course ID.
     * @param float $competencygradethreshold Competency grade threshold.
     * @param float $maxcompetentpercentage Maximum competent percentage.
     * @return array
     */
    public static function execute($courseid, $competencygradethreshold, $maxcompetentpercentage) {
        global $DB;

        // Validate parameters and context.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'                   => $courseid,
            'competency_grade_threshold' => $competencygradethreshold,
            'max_competent_percentage'   => $maxcompetentpercentage,
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/grade:viewall', $context);

        // Get course data.
        $course = $DB->get_record('course', ['id' => $params['courseid']], 'id, fullname, shortname', MUST_EXIST);

        // Get all students in the course.
        $students = helper::get_students_in_course($courseid);
        if (empty($students)) {
            return [
                'course' => [
                    'id'         => $course->id,
                    'fullname'   => $course->fullname,
                    'shortname'  => $course->shortname,
                    'activities' => [],
                ],
            ];
        }

        // Get all graded activities in the course.
        $modules = $DB->get_records_sql(
            "SELECT DISTINCT
                gi.id AS gradeitemid,
                gi.itemname AS name,
                gi.itemmodule AS modname,
                gi.iteminstance,
                gi.grademax,
                cm.id AS cmid
              FROM {grade_items} gi
              JOIN {modules} m ON m.name = gi.itemmodule
              JOIN {course_modules} cm ON cm.module = m.id
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
                    'id'         => $course->id,
                    'fullname'   => $course->fullname,
                    'shortname'  => $course->shortname,
                    'activities' => [],
                ],
            ];
        }

        // Get all grades for all grade items.
        list($gradeitemsql, $gradeitemparams) = $DB->get_in_or_equal(
            array_column($modules, 'gradeitemid'),
            SQL_PARAMS_NAMED,
            'giid'
        );

        $gradesall = $DB->get_records_sql(
            "SELECT id, userid, itemid, usermodified, finalgrade
               FROM {grade_grades}
              WHERE itemid $gradeitemsql",
            $gradeitemparams
        );

        // Index grades by item and user.
        $gradesbyitem = [];
        $gradesbyuser = [];
        foreach ($gradesall as $g) {
            $gradesbyitem[$g->itemid][$g->userid] = [
                'finalgrade'   => $g->finalgrade,
                'usermodified' => $g->usermodified,
            ];
            $gradesbyuser[$g->userid][$g->itemid] = $g->finalgrade;
        }

        $underperformingactivities = [];
        $totalstudents             = count($students);

        $participatedbymodule = helper::get_all_participated_users_in_course($courseid, $modules, $gradesbyuser);

        foreach ($modules as $module) {
            if ($module->modname === 'assign') {
                $assign = $DB->get_record('assign', ['id' => $module->iteminstance], 'grade');
                if ($assign && $assign->grade == 0) {
                    continue;
                }
            } else if ($module->modname === 'quiz') {
                $quiz = $DB->get_record('quiz', ['id' => $module->iteminstance], 'grade');
                if ($quiz && $quiz->grade == 0) {
                    continue;
                }
            } else if ($module->modname === 'forum') {
                $forum = $DB->get_record('forum', ['id' => $module->iteminstance], 'assessed');
                if ($forum && $forum->assessed == 0) {
                    continue;
                }
            } else if ($module->modname === 'workshop') {
                $workshop = $DB->get_record('workshop', ['id' => $module->iteminstance], 'grade, gradinggrade');
                if ($workshop && $workshop->grade == 0 && $workshop->gradinggrade == 0) {
                    continue;
                }
            } else if ($module->modname === 'lesson') {
                $lesson = $DB->get_record('lesson', ['id' => $module->iteminstance], 'grade');
                if ($lesson && $lesson->grade == 0) {
                    continue;
                }
            }

            $participatedusers      = $participatedbymodule[$module->gradeitemid] ?? [];
            $studentssubmitted      = 0;
            $studentscompetent      = 0;
            $studentsactuallygraded = 0;
            $incompetentstudents    = [];

            foreach ($students as $student) {
                if (!empty($participatedusers[$student->id])) {
                    $studentssubmitted++;
                    $grade = $gradesbyitem[$module->gradeitemid][$student->id]['finalgrade'] ?? null;

                    if ($grade !== null) {
                        $studentsactuallygraded++;
                        // Hitung persentase nilai (nilai / maksimal nilai * 100).
                        $normalizedgrade = $module->grademax > 0
                            ? ($grade / $module->grademax) * 100.0
                            : 0.0;
                    }

                    // Bandingkan nilai yang sudah dinormalisasi dengan threshold (80.0).
                    if ($grade !== null && $normalizedgrade >= $params['competency_grade_threshold']) {
                        $studentscompetent++;
                    } else {
                        $incompetentstudents[] = [
                            'id'        => (int)$student->id,
                            'firstname' => $student->firstname,
                            'lastname'  => $student->lastname,
                            'email'     => $student->email,
                        ];
                    }
                }
            }

            $competentpercentage = $studentssubmitted > 0 ? ($studentscompetent / $studentssubmitted) * 100 : 100;

            if ($studentssubmitted > 0 &&
                $studentsactuallygraded > 0 &&
                $competentpercentage <= $params['max_competent_percentage']) {
                $underperformingactivities[] = [
                    'id'                   => (int)$module->cmid,
                    'name'                 => $module->name,
                    'module'               => $module->modname,
                    'total_students'       => $totalstudents,
                    'students_submitted'   => $studentssubmitted,
                    'students_competent'   => $studentscompetent,
                    'students_incompetent' => $incompetentstudents,
                ];
            }
        }

        return [
            'course' => [
                'id'         => $course->id,
                'fullname'   => $course->fullname,
                'shortname'  => $course->shortname,
                'activities' => $underperformingactivities,
            ],
        ];
    }

    /**
     * Define the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'course' => new external_single_structure([
                'id'         => new external_value(PARAM_INT, 'Course ID'),
                'fullname'   => new external_value(PARAM_TEXT, 'Course full name'),
                'shortname'  => new external_value(PARAM_TEXT, 'Course short name'),
                'activities' => new external_multiple_structure(
                    new external_single_structure([
                        'id'                   => new external_value(PARAM_INT, 'Activity ID.'),
                        'name'                 => new external_value(PARAM_TEXT, 'Activity name.'),
                        'module'               => new external_value(PARAM_TEXT, 'Module type.'),
                        'total_students'       => new external_value(PARAM_INT, 'Total students.'),
                        'students_submitted'   => new external_value(PARAM_INT, 'Students submitted.'),
                        'students_competent'   => new external_value(PARAM_INT, 'Competent students.'),
                        'students_incompetent' => new external_multiple_structure(
                            new external_single_structure([
                                'id'        => new external_value(PARAM_INT, 'Student ID'),
                                'firstname' => new external_value(PARAM_TEXT, 'First name'),
                                'lastname'  => new external_value(PARAM_TEXT, 'Last name'),
                                'email'     => new external_value(PARAM_TEXT, 'Email'),
                            ]),
                            'List of incompetent students.'
                        ),
                    ]),
                    'List of underperforming activities.'
                ),
            ]),
        ]);
    }
}