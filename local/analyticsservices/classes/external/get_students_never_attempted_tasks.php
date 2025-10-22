<?php

namespace local_analyticsservices\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;

defined('MOODLE_INTERNAL') || die();

class get_students_never_attempted_tasks extends external_api
{
    public static function execute_parameters()
    {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
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

        // Get student role id
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
        if (!$studentroleid) $studentroleid = 5;

        $students = get_role_users($studentroleid, $context);
        if (empty($students)) {
            return ['students' => []];
        }

        $studentids = array_keys($students);

        // List modules yang bisa dinilai
        $modules = $DB->get_records_sql(
            "SELECT cm.id AS cmid, gi.itemmodule AS modname
             FROM {grade_items} gi
             JOIN {modules} m ON m.name = gi.itemmodule
             JOIN {course_modules} cm ON cm.module = m.id AND cm.course = gi.courseid
             WHERE gi.courseid = :courseid
               AND gi.itemtype = 'mod'
               AND gi.itemmodule IS NOT NULL",
            ['courseid' => $courseid]
        );

        if (empty($modules)) {
            return ['students' => []];
        }

        $cmids = array_keys($modules);

        list($cm_sql, $cm_params) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cmid');
        list($user_sql, $user_params) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'userid');

        $sql = "
            SELECT DISTINCT userid
            FROM {logstore_standard_log}
            WHERE courseid = :courseid
              AND contextinstanceid $cm_sql
              AND userid $user_sql
              AND action IN ('attempted', 'submitted')
        ";

        $params = ['courseid' => $courseid] + $cm_params + $user_params;
        $activeusers = $DB->get_records_sql($sql, $params);
        $activeids = array_keys($activeusers);

        // --- Ambil mahasiswa yang tidak ada di daftar aktif ---
        $inactiveids = array_diff($studentids, $activeids);

        $results = [];
        foreach ($inactiveids as $userid) {
            $user = $students[$userid];
            $results[] = [
                'id' => $user->id,
                'username' => $user->username,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'role' => $user->roleshortname,
                'email' => $user->email
            ];
        }

        return ['students' => $results];
    }

    /**
     * Struktur output.
     */
    public static function execute_returns()
    {
        return new external_single_structure([
            'students' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'User ID'),
                    'username' => new external_value(PARAM_TEXT, 'Username'),
                    'firstname' => new external_value(PARAM_TEXT, 'First name'),
                    'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                    'email' => new external_value(PARAM_TEXT, 'Email'),
                    'role' => new external_value(PARAM_TEXT, 'Role'),
                ])
            )
        ]);
    }
}
