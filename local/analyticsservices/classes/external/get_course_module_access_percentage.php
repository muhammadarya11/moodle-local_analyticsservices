<?php

namespace local_analyticsservices\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;


defined('MOODLE_INTERNAL') || die();

class get_course_module_access_percentage extends external_api
{
    public static function execute_parameters()
    {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID')
        ]);
    }

    public static function execute($courseid)
    {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid
        ]);

        $context = context_course::instance($courseid);
        self::validate_context($context);

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', MUST_EXIST);

        $students = get_role_users(5, $context);
        $studentids = array_map(fn($s) => (int)$s->id, $students);
        $totalstudents = count($studentids);


        if ($totalstudents === 0) {
            return [
                'course' => [
                    'name' => $course->fullname,
                    'shortname' => $course->shortname,
                    'modules' => []
                ]
            ];
        }

        $sql = "SELECT cm.id AS cmid, cm.instance, m.name AS modname 
        FROM {course_modules} cm
        JOIN {modules} m ON m.id = cm.module 
        WHERE cm.course = :courseid
        AND cm.deletioninprogress = 0
        AND cm.visible = 1";

        $modules = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        if (empty($modules)) {
            return [
                'course' => [
                    'name' => $course->fullname,
                    'shortname' => $course->shortname,
                    'modules' => []
                ]
            ];
        }

        $cmids = array_keys($modules);
        list($cm_sql, $cm_params) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cmid');
        list($user_sql, $user_params) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'userid');

        $sql = "SELECT contextinstanceid AS cmid, COUNT(DISTINCT userid) AS viewed_count
        FROM {logstore_standard_log}
        WHERE courseid = :courseid
            AND contextinstanceid $cm_sql
            AND userid $user_sql
            AND action = 'viewed'
        GROUP BY cmid";

        $params = array_merge(['courseid' => $courseid], $cm_params, $user_params);

        $logdata = $DB->get_records_sql($sql, $params);

        $resultModules = [];
        foreach ($modules as $mod) {
            $viewedcount = isset($logdata[$mod->cmid]) ? $logdata[$mod->cmid]->viewed_count : 0;
            $percent = round(($viewedcount / $totalstudents) * 100, 2);

            $resultModules[] = [
                'cmid' => $mod->cmid,
                'modname' => $mod->modname,
                'percentage_viewed' => $percent
            ];
        }

        return [
            'course' => [
                'name' => $course->fullname,
                'shortname' => $course->shortname,
                'modules' => $resultModules
            ]
        ];
    }

    public static function execute_returns()
    {
        return new external_single_structure([
            'course' => new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'Nama course'),
                'shortname' => new external_value(PARAM_TEXT, 'Shortname course'),
                'modules' => new external_multiple_structure(
                    new external_single_structure([
                        'cmid' => new external_value(PARAM_INT, 'Course module ID'),
                        'modname' => new external_value(PARAM_TEXT, 'Nama module (type)'),
                        'percentage_viewed' => new external_value(PARAM_FLOAT, 'Persentase mahasiswa yang membuka module ini')
                    ])
                )
            ])
        ]);
    }
}
