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
 * External function to get active course ids since a timestamp.
 *
 * @package   local_analyticsservices
 * @copyright 2026, Arya Kusuma <muhammadaryakusuma@gmail.com>
 * @copyright 2026, Safiyyah Yahya <safiyyahyahya163@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_analyticsservices\external\course;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_multiple_structure;
use core_external\external_single_structure;

/**
 * Class get_active_course_ids_since.
 */
class get_active_course_ids_since extends external_api {

    /**
     * Define the parameters for execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'since' => new external_value(PARAM_INT, 'Timestamp for change data capture window'),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $since Timestamp.
     * @return array
     */
    public static function execute($since) {
        global $DB;

        // Validasi parameter.
        $params = self::validate_parameters(self::execute_parameters(), ['since' => $since]);
        $since = $params['since'];
        $now = time();

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $activecourses = [];

        // Cek aktivitas klik di logstore_standard_log.
        $sqllogs = "SELECT DISTINCT courseid
                      FROM {logstore_standard_log}
                     WHERE timecreated >= ? AND timecreated <= ?
                       AND courseid > 1";
        $logcourses = $DB->get_fieldset_sql($sqllogs, [$since, $now]);

        if (!empty($logcourses)) {
            $activecourses = array_merge($activecourses, $logcourses);
        }

        // Cek aktivitas nilai di grade_grades_history.
        $sqlgrades = "SELECT DISTINCT i.courseid
                        FROM {grade_grades_history} gh
                        JOIN {grade_items} i ON gh.itemid = i.id
                       WHERE gh.timemodified >= ? AND gh.timemodified <= ?";
        $gradecourses = $DB->get_fieldset_sql($sqlgrades, [$since, $now]);

        if (!empty($gradecourses)) {
            $activecourses = array_merge($activecourses, $gradecourses);
        }

        // Memastikan unik dan index-nya rapi.
        $uniquecourses = array_values(array_unique($activecourses));

        return [
            'courseids' => $uniquecourses,
        ];
    }

    /**
     * Define the return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'courseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course ID')
            ),
        ]);
    }
}