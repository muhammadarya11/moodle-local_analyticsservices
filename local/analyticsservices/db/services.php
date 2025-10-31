<?php

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_services_get_graded_course_activities' => [
        'classname'   => 'local_analyticsservices\\external\\get_graded_course_activities',
        'methodname'  => 'execute',
        'description' => 'Get graded course activities',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_services_get_course_access_by_ipgroup' => [
        'classname'   => 'local_analyticsservices\\external\\get_course_access_by_ipgroup',
        'methodname'  => 'execute',
        'description' => 'Get course access by ipgroup',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_services_get_course_access_by_timeperiod' => [
        'classname'   => 'local_analyticsservices\\external\\get_course_access_by_timeperiod',
        'methodname'  => 'execute',
        'description' => 'Get course access by timeperiod',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_services_get_course_module_access_percentage' => [
        'classname'   => 'local_analyticsservices\\external\\get_course_module_access_percentage',
        'methodname'  => 'execute',
        'description' => 'Get course module access percentage',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_services_get_students_never_attempted_tasks' => [
        'classname'   => 'local_analyticsservices\\external\\get_students_never_attempted_tasks',
        'methodname'  => 'execute',
        'description' => 'Get students never attempted tasks',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_services_get_uncompetent_activities' => [
        'classname'   => 'local_analyticsservices\\external\\get_uncompetent_activities',
        'methodname'  => 'execute',
        'description' => 'Get uncompetent activities',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_services_get_students_competent_percentage' => [
        'classname'   => 'local_analyticsservices\\external\\get_students_competent_percentage',
        'methodname'  => 'execute',
        'description' => 'Get students competent percentage',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],

    // Function untuk section
    'local_services_get_course_modules_info_by_section' => [
        'classname'   => 'local_analyticsservices\\external\\section\\get_course_modules_info_by_section',
        'methodname'  => 'execute',
        'description' => 'Get course modules info by section',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_services_get_quiz_attempt_frequency_by_section' => [
        'classname'   => 'local_analyticsservices\\external\\section\\get_quiz_attempt_frequency_by_section',
        'methodname'  => 'execute',
        'description' => 'Get quiz attempt frequency by section',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_services_get_quiz_attempt_average_time' => [
        'classname'   => 'local_analyticsservices\\external\\section\\get_quiz_attempt_average_time_by_section',
        'methodname'  => 'execute',
        'description' => 'Get quiz attempt average time by section',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
    'local_services_get_grade_distribution_by_section' => [
        'classname'   => 'local_analyticsservices\\external\\section\\get_grade_distribution_by_section',
        'methodname'  => 'execute',
        'description' => 'Get grade distribution by section',
        'type'        => 'read',
        'ajax'        => true,
        'loginrequired' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]
    ],
];
