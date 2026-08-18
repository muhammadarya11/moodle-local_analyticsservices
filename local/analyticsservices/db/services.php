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
 * Services definitions.
 *
 * @package   local_analyticsservices
 * @copyright 2026, Arya Kusuma <muhammadaryakusuma@gmail.com>
 * @copyright 2026, Safiyyah Yahya <safiyyahyahya163@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_analyticsservices_get_graded_course_activities' => [
        'classname'   => 'local_analyticsservices\\external\\course\\get_graded_course_activities',
        'methodname'  => 'execute',
        'description' => 'Get graded course activities',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_course_access_by_ipgroup' => [
        'classname'   => 'local_analyticsservices\\external\\course\\get_course_access_by_ipgroup',
        'methodname'  => 'execute',
        'description' => 'Get course access by ipgroup',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_course_access_by_timeperiod' => [
        'classname'   => 'local_analyticsservices\\external\\course\\get_course_access_by_timeperiod',
        'methodname'  => 'execute',
        'description' => 'Get course access by timeperiod',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_course_module_access_percentage' => [
        'classname'   => 'local_analyticsservices\\external\\course\\get_course_module_access_percentage',
        'methodname'  => 'execute',
        'description' => 'Get course module access percentage',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_students_competent_percentage' => [
        'classname'   => 'local_analyticsservices\\external\\course\\get_students_competent_percentage',
        'methodname'  => 'execute',
        'description' => 'Get students competent percentage',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_course_stats' => [
        'classname'   => 'local_analyticsservices\\external\\course\\get_course_stats',
        'methodname'  => 'execute',
        'description' => 'Get course stats',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_inactive_students' => [
        'classname'   => 'local_analyticsservices\\external\\course\\get_inactive_students',
        'methodname'  => 'execute',
        'description' => 'Get inactive students',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_underperforming_course_activities' => [
        'classname'   => 'local_analyticsservices\\external\\course\\get_underperforming_course_activities',
        'methodname'  => 'execute',
        'description' => 'Get underperforming course activities',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_students_never_attempted_tasks' => [
        'classname'   => 'local_analyticsservices\\external\\course\\get_students_never_attempted_tasks',
        'methodname'  => 'execute',
        'description' => 'Get students who have never attempted tasks',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_uncompetent_activities' => [
        'classname'   => 'local_analyticsservices\\external\\course\\get_uncompetent_activities',
        'methodname'  => 'execute',
        'description' => 'Get uncompetent activities',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],

    // Function untuk section.
    'local_analyticsservices_get_course_modules_info_by_section' => [
        'classname'   => 'local_analyticsservices\\external\\section\\get_course_modules_info_by_section',
        'methodname'  => 'execute',
        'description' => 'Get course modules info by section',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_quiz_attempt_frequency_by_section' => [
        'classname'   => 'local_analyticsservices\\external\\section\\get_quiz_attempt_frequency_by_section',
        'methodname'  => 'execute',
        'description' => 'Get quiz attempt frequency by section',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_quiz_attempt_average_time_by_section' => [
        'classname'   => 'local_analyticsservices\\external\\section\\get_quiz_attempt_average_time_by_section',
        'methodname'  => 'execute',
        'description' => 'Get quiz attempt average time by section',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_students_competent_percentage_by_section' => [
        'classname'   => 'local_analyticsservices\\external\\section\\get_students_competent_percentage_by_section',
        'methodname'  => 'execute',
        'description' => 'Get students competent percentage by section',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_graded_course_activities_by_section' => [
        'classname'   => 'local_analyticsservices\\external\\section\\get_graded_course_activities_by_section',
        'methodname'  => 'execute',
        'description' => 'Get graded course activities by section',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'local_analyticsservices_get_student_competency_level_by_section' => [
        'classname'   => 'local_analyticsservices\\external\\section\\get_student_competency_level_by_section',
        'methodname'  => 'execute',
        'description' => 'Get student competency level by section',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
