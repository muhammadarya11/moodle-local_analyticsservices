<?php

namespace local_analyticsservices;

use context_course;
use DateTime;

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

    public static function convert_timestamp_to_datetime($timestamp): DateTime
    {
        return (new DateTime())->setTimestamp($timestamp);
    }

    /**
     * Get user IDs who participated (submitted or graded) in a graded activity.
     *
     * @param object $module object containing modname, iteminstance, cmid, gradeitemid
     * @param array $grades_by_user [userid][gradeitemid] = finalgrade
     * @return array array of userid => true who participated
     */
    public static function get_participated_users($module, $grades_by_user): array
    {
        global $DB;
        $participated = [];

        // 1. Cek dari submission/attempt tabel spesifik modul
        if ($module->modname === 'assign') {
            $records = $DB->get_records_sql(
                "SELECT DISTINCT userid, id FROM {assign_submission} WHERE assignment = ? AND status = 'submitted'",
                [$module->iteminstance]
            );
            foreach ($records as $r) {
                $participated[$r->userid] = true;
            }
        } elseif ($module->modname === 'quiz') {
            $records = $DB->get_records_sql(
                "SELECT DISTINCT userid, id FROM {quiz_attempts} WHERE quiz = ? AND state = 'finished'",
                [$module->iteminstance]
            );
            foreach ($records as $r) {
                $participated[$r->userid] = true;
            }
        } elseif ($module->modname === 'forum') {
            $records = $DB->get_records_sql(
                "SELECT DISTINCT p.userid, p.id FROM {forum_posts} p JOIN {forum_discussions} d ON d.id = p.discussion WHERE d.forum = ?",
                [$module->iteminstance]
            );
            foreach ($records as $r) {
                $participated[$r->userid] = true;
            }
        } else {
            // Untuk modul lain, cek dari log store standar
            $records = $DB->get_records_sql(
                "SELECT DISTINCT userid, id FROM {logstore_standard_log} WHERE contextinstanceid = ? AND action IN ('submitted', 'viewed', 'attempted')",
                [$module->cmid]
            );
            foreach ($records as $r) {
                $participated[$r->userid] = true;
            }
        }

        // 2. ATAU jika dosen sudah menilai terlebih dahulu (finalgrade IS NOT NULL di grade_grades)
        foreach ($grades_by_user as $userid => $userGrades) {
            if (isset($userGrades[$module->gradeitemid]) && $userGrades[$module->gradeitemid] !== null) {
                $participated[$userid] = true;
            }
        }

        return $participated;
    }
}

