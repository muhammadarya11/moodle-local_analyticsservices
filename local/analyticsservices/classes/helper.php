<?php

namespace local_analyticsservices;

use context_course;

defined('MOODLE_INTERNAL') || die();

class helper
{

    /**
     * Ambil daftar student di course.
     *
     * @param int $courseid
     * @return array daftar user object
     */
    public static function get_students_in_course(int $courseid): array
    {
        global $DB;

        $context = context_course::instance($courseid);

        // Get student role id
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
        if (!$studentroleid) $studentroleid = 5;

        $students = get_role_users($studentroleid, $context);

        return $students ?: [];
    }

    public static function get_log_table_name(): string
    {
        global $DB;
    }
}
