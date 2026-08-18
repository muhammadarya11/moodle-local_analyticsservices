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
 * External function to get graded course activities by section.
 *
 * @package   local_analyticsservices
 * @copyright 2026, Arya Kusuma <muhammadaryakusuma@gmail.com>
 * @copyright 2026, Safiyyah Yahya <safiyyahyahya163@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_analyticsservices\external\section;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;
use local_analyticsservices\helper;

/**
 * Class get_graded_course_activities_by_section.
 */
class get_graded_course_activities_by_section extends external_api {

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
     * Get data of activities that have been worked on and graded based on section.
     *
     * @param int $sectionid Section ID.
     * @return array
     */
    public static function execute($sectionid) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'sectionid' => $sectionid,
        ]);

        // Get section data and course ID.
        $section  = $DB->get_record('course_sections', ['id' => $params['sectionid']], 'id, course, name, section', MUST_EXIST);
        $courseid = $section->course;

        $context = context_course::instance($courseid);
        self::validate_context($context);
        require_capability('moodle/grade:viewall', $context);

        // Get course data.
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);

        // Get students enrolled in this course.
        $students      = helper::get_students_in_course($courseid);
        $totalstudents = count($students);

        if ($totalstudents == 0) {
            return [
                'section' => [
                    'id'              => $sectionid,
                    'name'            => $section->name ?? 'Section ' . $section->section,
                    'section_number'  => (int)$section->section,
                    'courseid'        => $courseid,
                    'coursename'      => $course->fullname,
                    'courseshortname' => $course->shortname,
                    'activities'      => [],
                ],
            ];
        }

        $sql = "SELECT cm.id AS cmid,
                    m.name AS itemmodule,
                    cm.instance AS iteminstance,
                    COALESCE(gi.id, 0) AS gradeitemid,
                    gi.itemname,
                    gi.gradetype,
                    COUNT(DISTINCT CASE WHEN g.finalgrade IS NOT NULL THEN g.userid END) AS gradedcount
                FROM {course_modules} cm
                JOIN {modules} m ON m.name = m.name AND cm.module = m.id
                LEFT JOIN {grade_items} gi ON gi.itemmodule = m.name
                    AND gi.iteminstance = cm.instance
                    AND gi.courseid = cm.course
                LEFT JOIN {grade_grades} g ON g.itemid = gi.id
                WHERE cm.course = :courseid
                  AND cm.section = :sectionid
                  AND cm.deletioninprogress = 0
                  AND m.name IN ('assign', 'quiz', 'forum', 'workshop', 'lesson', 'scorm')
                GROUP BY cm.id, m.name, cm.instance, gi.id, gi.itemname, gi.gradetype";

        $records = $DB->get_records_sql($sql, ['courseid' => $courseid, 'sectionid' => $params['sectionid']]);

        $gradeitemids = [];
        foreach ($records as $r) {
            if ($r->gradeitemid) {
                $gradeitemids[] = $r->gradeitemid;
            }
        }

        $gradesbyuser = [];
        if (!empty($gradeitemids)) {
            list($gradeitemsql, $gradeitemparams) = $DB->get_in_or_equal($gradeitemids, SQL_PARAMS_NAMED, 'giid');
            $gradesall = $DB->get_records_sql(
                "SELECT id, userid, itemid, finalgrade FROM {grade_grades} WHERE itemid $gradeitemsql",
                $gradeitemparams
            );
            foreach ($gradesall as $g) {
                $gradesbyuser[$g->userid][$g->itemid] = $g->finalgrade;
            }
        }

        $results = helper::format_graded_activities_results($records, $gradesbyuser, $students);

        return [
            'section' => [
                'id'              => $sectionid,
                'name'            => $section->name ?? 'Section ' . $section->section,
                'section_number'  => (int)$section->section,
                'courseid'        => $courseid,
                'coursename'      => $course->fullname,
                'courseshortname' => $course->shortname,
                'activities'      => $results,
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
            'section' => new external_single_structure([
                'id'              => new external_value(PARAM_INT, 'Section ID'),
                'name'            => new external_value(PARAM_TEXT, 'Nama section.'),
                'section_number'  => new external_value(PARAM_INT, 'Section number.'),
                'courseid'        => new external_value(PARAM_INT, 'Course ID'),
                'coursename'      => new external_value(PARAM_TEXT, 'Nama course.'),
                'courseshortname' => new external_value(PARAM_TEXT, 'Shortname course.'),
                'activities'      => new external_multiple_structure(
                    new external_single_structure([
                        'id'                 => new external_value(PARAM_INT, 'Activity instance ID'),
                        'name'               => new external_value(PARAM_TEXT, 'Activity name'),
                        'module'             => new external_value(PARAM_TEXT, 'Module name'),
                        'total_students'     => new external_value(PARAM_INT, 'Total number of students in course.'),
                        'students_submitted' => new external_value(PARAM_INT, 'Number of students who have submitted.'),
                        'students_graded'    => new external_value(PARAM_INT, 'Number of students who have been graded.'),
                        'has_grading'        => new external_value(PARAM_BOOL, 'Whether the activity has grading.'),
                    ]),
                    'List of graded activities in the section.'
                ),
            ]),
        ]);
    }
}
