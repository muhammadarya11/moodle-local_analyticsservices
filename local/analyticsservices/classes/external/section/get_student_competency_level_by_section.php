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
 * External function to get student competency level by section.
 *
 * @package   local_analyticsservices
 * @copyright 2026, Arya Kusuma <muhammadaryakusuma@gmail.com>
 * @copyright 2026, Safiyyah Yahya <safiyyahyahya163@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_analyticsservices\external\section;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;
use local_analyticsservices\helper;

/**
 * Class get_student_competency_level_by_section.
 */
class get_student_competency_level_by_section extends external_api {

    /**
     * Define the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'sectionid' => new external_value(PARAM_INT, 'Section ID'),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $sectionid Section ID.
     * @return array
     */
    public static function execute($sectionid) {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'sectionid' => $sectionid,
        ]);

        // Validate course context.
        $section = $DB->get_record('course_sections', ['id' => $params['sectionid']], 'id, course', MUST_EXIST);
        $courseid = $section->course;
        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/grade:viewall', $context);

        // Get students enrolled in this course.
        $students = helper::get_students_in_course($courseid);

        if (empty($students)) {
            return [
                'courseid'  => $courseid,
                'sectionid' => $sectionid,
                'levels'    => [
                    'competent'          => 0,
                    'partially_competent' => 0,
                    'not_competent'      => 0,
                ],
            ];
        }

        // Get graded activities in this section.
        $gradedmodules = $DB->get_records_sql(
            "SELECT DISTINCT
                gi.id AS gradeitemid,
                gi.grademax,
                cm.instance AS iteminstance,
                cm.id AS cmid
              FROM {grade_items} gi
              JOIN {modules} m ON m.name = gi.itemmodule
              JOIN {course_modules} cm ON cm.module = m.id
                   AND cm.instance = gi.iteminstance
                   AND cm.course = gi.courseid
                   AND cm.visible = 1
                   AND cm.section = :sectionid
             WHERE gi.courseid = :courseid
               AND cm.deletioninprogress = 0
               AND gi.itemtype = 'mod'
               AND gi.itemmodule IS NOT NULL
               AND gi.gradetype != 0
               AND gi.grademax > 0
               AND (gi.itemmodule != 'assign'
                    OR (SELECT grade FROM {assign} WHERE id = gi.iteminstance) != 0)",
            [
                'courseid'  => $courseid,
                'sectionid' => $params['sectionid'],
            ]
        );

        $totalstudents = count($students);

        if (empty($gradedmodules)) {
            return [
                'courseid'  => $courseid,
                'sectionid' => $sectionid,
                'levels'    => [
                    'competent'           => $totalstudents,
                    'partially_competent' => 0,
                    'not_competent'       => 0,
                ],
            ];
        }

        list($gradeitemsql, $gradeitemparams) = $DB->get_in_or_equal(
            array_column($gradedmodules, 'gradeitemid'),
            SQL_PARAMS_NAMED,
            'giid'
        );

        $gradesall = $DB->get_records_sql(
            "SELECT id, userid, itemid, finalgrade
               FROM {grade_grades}
              WHERE itemid $gradeitemsql",
            $gradeitemparams
        );

        $gradesbyuser = [];
        foreach ($gradesall as $g) {
            $gradesbyuser[$g->userid][$g->itemid] = $g->finalgrade;
        }

        $competent    = 0;
        $partially    = 0;
        $notcompetent = 0;

        foreach ($students as $student) {
            $uid        = $student->id;
            $usergrades = $gradesbyuser[$uid] ?? [];
            $gradesum   = 0.0;
            $gradedcount = 0;

            foreach ($gradedmodules as $module) {
                if (isset($usergrades[$module->gradeitemid]) && $usergrades[$module->gradeitemid] !== null) {
                    $grade      = $usergrades[$module->gradeitemid];
                    $normalized = $module->grademax > 0 ? ($grade / $module->grademax) * 100.0 : 0.0;
                    $gradesum  += $normalized;
                    $gradedcount++;
                }
            }

            if ($gradedcount === 0) {
                $notcompetent++;
            } else {
                $avg = $gradesum / $gradedcount;
                if ($avg >= 80.0) {
                    $competent++;
                } else if ($avg >= 50.0) {
                    $partially++;
                } else {
                    $notcompetent++;
                }
            }
        }

        return [
            'courseid'  => $courseid,
            'sectionid' => $sectionid,
            'levels'    => [
                'competent'           => $competent,
                'partially_competent' => $partially,
                'not_competent'       => $notcompetent,
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
            'courseid'  => new external_value(PARAM_INT, 'Course ID'),
            'sectionid' => new external_value(PARAM_INT, 'Section ID'),
            'levels'    => new external_single_structure([
                'competent'           => new external_value(PARAM_INT, 'Number of competent students.'),
                'partially_competent' => new external_value(PARAM_INT, 'Number of partially competent students.'),
                'not_competent'       => new external_value(PARAM_INT, 'Number of not competent students.'),
            ]),
        ]);
    }
}
