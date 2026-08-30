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
 * Helper class for local_analyticsservices plugin.
 *
 * @package   local_analyticsservices
 * @copyright 2026, Arya Kusuma <muhammadaryakusuma@gmail.com>
 * @copyright 2026, Safiyyah Yahya <safiyyahyahya163@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_analyticsservices;

use context_course;
use DateTime;

/**
 * General helper utilities for the analyticsservices plugin.
 */
class helper {

    /**
     * Ambil daftar student di course (hanya yang aktif terenrol).
     *
     * @param int $courseid Course ID.
     * @return array daftar user object.
     */
    public static function get_students_in_course(int $courseid): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/lib/enrollib.php');

        $context = context_course::instance($courseid);

        // Get student role id.
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
        if (!$studentroleid) {
            $studentroleid = 5;
        }

        // Dapatkan daftar pengguna yang ditugaskan sebagai student (mengabaikan status enrol).
        $roleusers = get_role_users($studentroleid, $context, false, 'u.id', 'u.id');
        if (empty($roleusers)) {
            return [];
        }

        // Dapatkan daftar pengguna yang BENAR-BENAR AKTIF ENROLMENT-NYA (tidak tersuspend)
        // Parameter ke-8 (true) memastikan hanya pengguna aktif yang ditarik!
        $enrolledusers = get_enrolled_users($context, '', 0, 'u.id, u.firstname, u.lastname, u.email', null, 0, 0, true);

        // Gabungkan keduanya (Irisan).
        $students = [];
        foreach ($enrolledusers as $user) {
            // Jika dia aktif DAN dia adalah student (bukan dosen), masukkan ke dalam array!
            if (isset($roleusers[$user->id])) {
                $students[$user->id] = $user;
            }
        }

        return $students;
    }

    /**
     * Convert a UNIX timestamp to a DateTime object.
     *
     * @param int $timestamp UNIX timestamp.
     * @return DateTime
     */
    public static function convert_timestamp_to_datetime($timestamp): DateTime {
        return (new DateTime())->setTimestamp($timestamp);
    }

    /**
     * Get user IDs who participated (submitted or graded) in a graded activity.
     *
     * @param object $module Object containing modname, iteminstance, cmid, gradeitemid.
     * @param array $gradesbyuser [userid][gradeitemid] = finalgrade.
     * @return array Array of userid => true who participated.
     */
    public static function get_participated_users($module, $gradesbyuser): array {
        global $DB;
        $participated = [];

        // Check from submission/attempt tables for specific module types.
        if ($module->modname === 'assign') {
            $records = $DB->get_records_sql(
                "SELECT DISTINCT userid, id FROM {assign_submission} WHERE assignment = ? AND status = 'submitted'",
                [$module->iteminstance]
            );
            foreach ($records as $r) {
                $participated[$r->userid] = true;
            }
        } else if ($module->modname === 'quiz') {
            // Preview = 0 memastikan attempt preview dari admin/guru tidak dihitung.
            $records = $DB->get_records_sql(
                "SELECT DISTINCT userid, id FROM {quiz_attempts} WHERE quiz = ? AND state = 'finished' AND preview = 0",
                [$module->iteminstance]
            );
            foreach ($records as $r) {
                $participated[$r->userid] = true;
            }
        } else if ($module->modname === 'forum') {
            $records = $DB->get_records_sql(
                "SELECT DISTINCT p.userid, p.id
                   FROM {forum_posts} p
                   JOIN {forum_discussions} d ON d.id = p.discussion
                  WHERE d.forum = ?",
                [$module->iteminstance]
            );
            foreach ($records as $r) {
                $participated[$r->userid] = true;
            }
        } else {
            // For other modules, check from the standard log store.
            $records = $DB->get_records_sql(
                "SELECT DISTINCT userid, id
                   FROM {logstore_standard_log}
                  WHERE contextinstanceid = ?
                    AND action IN ('submitted', 'viewed', 'attempted')",
                [$module->cmid]
            );
            foreach ($records as $r) {
                $participated[$r->userid] = true;
            }
        }

        // Also consider graded by teacher (finalgrade IS NOT NULL in grade_grades).
        foreach ($gradesbyuser as $userid => $usergrades) {
            if (isset($usergrades[$module->gradeitemid]) && $usergrades[$module->gradeitemid] !== null) {
                $participated[$userid] = true;
            }
        }

        return $participated;
    }

    /**
     * Get all participated users for multiple graded modules in one bulk query.
     * This eliminates N+1 queries when calculating course analytics.
     *
     * @param int $courseid Course ID.
     * @param array $gradedmodules Array of graded module records.
     * @param array $gradesbyuser Nested array [userid][gradeitemid] = finalgrade.
     * @return array Nested array [gradeitemid][userid] = true.
     */
    public static function get_all_participated_users_in_course(int $courseid, array $gradedmodules, array $gradesbyuser): array {
        global $DB;
        $participatedbymodule = [];

        foreach ($gradedmodules as $module) {
            $participatedbymodule[$module->gradeitemid] = [];
        }

        $assigns = [];
        $quizzes = [];
        $forums = [];
        $othercmids = [];

        foreach ($gradedmodules as $module) {
            if ($module->modname === 'assign') {
                $assigns[$module->iteminstance] = $module->gradeitemid;
            } else if ($module->modname === 'quiz') {
                $quizzes[$module->iteminstance] = $module->gradeitemid;
            } else if ($module->modname === 'forum') {
                $forums[$module->iteminstance] = $module->gradeitemid;
            } else {
                $othercmids[$module->cmid] = $module->gradeitemid;
            }
        }

        // Bulk fetch assign submissions.
        if (!empty($assigns)) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($assigns), SQL_PARAMS_NAMED, 'assign');
            $rs = $DB->get_recordset_sql(
                "SELECT DISTINCT userid, assignment
                   FROM {assign_submission}
                  WHERE assignment $insql AND status = 'submitted'",
                $inparams
            );
            foreach ($rs as $r) {
                $gradeitemid = $assigns[$r->assignment];
                $participatedbymodule[$gradeitemid][$r->userid] = true;
            }
            $rs->close();
        }

        // Bulk fetch quizzes.
        if (!empty($quizzes)) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($quizzes), SQL_PARAMS_NAMED, 'quiz');
            $rs = $DB->get_recordset_sql(
                "SELECT DISTINCT userid, quiz
                   FROM {quiz_attempts}
                  WHERE quiz $insql AND state = 'finished' AND preview = 0",
                $inparams
            );
            foreach ($rs as $r) {
                $gradeitemid = $quizzes[$r->quiz];
                $participatedbymodule[$gradeitemid][$r->userid] = true;
            }
            $rs->close();
        }

        // Bulk fetch forums.
        if (!empty($forums)) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($forums), SQL_PARAMS_NAMED, 'forum');
            $rs = $DB->get_recordset_sql(
                "SELECT DISTINCT p.userid, d.forum
                   FROM {forum_posts} p
                   JOIN {forum_discussions} d ON d.id = p.discussion
                  WHERE d.forum $insql",
                $inparams
            );
            foreach ($rs as $r) {
                $gradeitemid = $forums[$r->forum];
                $participatedbymodule[$gradeitemid][$r->userid] = true;
            }
            $rs->close();
        }

        // Bulk fetch others from logstore_standard_log.
        if (!empty($othercmids)) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($othercmids), SQL_PARAMS_NAMED, 'cmid');
            $inparams['courseid'] = $courseid;
            $rs = $DB->get_recordset_sql(
                "SELECT DISTINCT userid, contextinstanceid
                   FROM {logstore_standard_log}
                  WHERE courseid = :courseid
                    AND contextinstanceid $insql
                    AND action IN ('submitted', 'viewed', 'attempted')",
                $inparams
            );
            foreach ($rs as $r) {
                if (isset($othercmids[$r->contextinstanceid])) {
                    $gradeitemid = $othercmids[$r->contextinstanceid];
                    $participatedbymodule[$gradeitemid][$r->userid] = true;
                }
            }
            $rs->close();
        }

        // Consider graded by teacher.
        foreach ($gradesbyuser as $userid => $usergrades) {
            foreach ($usergrades as $gradeitemid => $grade) {
                if ($grade !== null && isset($participatedbymodule[$gradeitemid])) {
                    $participatedbymodule[$gradeitemid][$userid] = true;
                }
            }
        }

        return $participatedbymodule;
    }

    /**
     * Calculate competency statistics for students.
     *
     * @param array $students Array of student records.
     * @param array $gradedmodules Array of graded module records.
     * @param array $gradesbyuser Nested array [userid][gradeitemid] = finalgrade.
     * @param array $participatedbymodule Nested array [gradeitemid][userid] = true.
     * @param array $params Parameters containing thresholds.
     * @return array [competentcount, incompetentcount, inactivecount]
     */
    public static function calculate_student_competency_stats(
        $students,
        $gradedmodules,
        $gradesbyuser,
        $participatedbymodule,
        $params
    ) {
        $competentcount   = 0;
        $incompetentcount = 0;
        $inactivecount    = 0;
        $totalgradedactivities = count($gradedmodules);

        if ($totalgradedactivities === 0) {
            return [count($students), 0, 0];
        }

        foreach ($students as $student) {
            $uid        = $student->id;
            $usergrades = $gradesbyuser[$uid] ?? [];

            $gradedparticipated = 0;
            $gradedgraded       = 0;
            $gradesum           = 0.0;

            foreach ($gradedmodules as $module) {
                if (!empty($participatedbymodule[$module->gradeitemid][$uid])) {
                    $gradedparticipated++;
                }

                if (isset($usergrades[$module->gradeitemid]) && $usergrades[$module->gradeitemid] !== null) {
                    $grade           = $usergrades[$module->gradeitemid];
                    $normalizedgrade = $module->grademax > 0
                        ? ($grade / $module->grademax) * 100.0
                        : 0.0;
                    $gradesum += $normalizedgrade;
                    $gradedgraded++;
                }
            }

            $gradedparticipationrate = ($gradedparticipated / $totalgradedactivities) * 100;

            if ($gradedparticipationrate <= $params['inactive_activity_rate']) {
                $inactivecount++;
                continue;
            }

            $averagegrade = $gradedgraded > 0
                ? ($gradesum / $gradedgraded)
                : 0.0;

            if (
                $gradedparticipationrate >= $params['competent_activity_rate'] &&
                $averagegrade >= $params['grade_threshold']
            ) {
                $competentcount++;
            } else {
                $incompetentcount++;
            }
        }

        return [$competentcount, $incompetentcount, $inactivecount];
    }

    /**
     * Format graded activities for external results.
     *
     * @param int $courseid Course ID.
     * @param array $records Database records for graded activities.
     * @param array $gradesbyuser Nested array [userid][itemid] = finalgrade.
     * @param array $students Array of student records.
     * @return array List of formatted activities.
     */
    public static function format_graded_activities_results($courseid, $records, $gradesbyuser, $students) {
        global $DB;
        $results = [];
        $totalstudents = count($students);

        $participatedbymodule = self::get_all_participated_users_in_course($courseid, $records, $gradesbyuser);

        foreach ($records as $r) {
            $name       = $r->itemname;
            $hasgrading = false;

            if ($r->gradeitemid && $r->gradetype != 0) {
                $hasgrading = true;
            }

            if ($r->itemmodule === 'assign') {
                $assign = $DB->get_record('assign', ['id' => $r->iteminstance], 'name, grade');
                if ($assign) {
                    $name = $assign->name;
                    if ($assign->grade == 0) {
                        $hasgrading = false;
                    }
                }
            } else if ($r->itemmodule === 'quiz') {
                $quiz = $DB->get_record('quiz', ['id' => $r->iteminstance], 'name');
                if ($quiz) {
                    $name = $quiz->name;
                }
            } else if ($r->itemmodule === 'forum') {
                $forum = $DB->get_record('forum', ['id' => $r->iteminstance], 'name');
                if ($forum) {
                    $name = $forum->name;
                }
            }

            if (empty($name)) {
                $name = 'Unnamed activity';
            }

            $r->modname        = $r->itemmodule;
            $participatedusers = $participatedbymodule[$r->gradeitemid] ?? [];

            // Hitung mahasiswa yang submit (pastikan ada di dalam array $students).
            $studentssubmitted = 0;
            foreach ($participatedusers as $uid => $true) {
                if (isset($students[$uid])) {
                    $studentssubmitted++;
                }
            }

            // Hitung mahasiswa yang dinilai (pastikan ada di dalam array $students).
            $studentsgraded = 0;
            if ($r->gradeitemid) {
                foreach ($gradesbyuser as $uid => $usergrades) {
                    if (isset($students[$uid]) && isset($usergrades[$r->gradeitemid]) && $usergrades[$r->gradeitemid] !== null) {
                        $studentsgraded++;
                    }
                }
            }

            $results[] = [
                'id'                 => (int)$r->iteminstance,
                'name'               => $name,
                'module'             => $r->itemmodule,
                'total_students'     => $totalstudents,
                'students_submitted' => (int)$studentssubmitted,
                'students_graded'    => (int)$studentsgraded,
                'has_grading'        => $hasgrading,
            ];
        }

        return $results;
    }
}