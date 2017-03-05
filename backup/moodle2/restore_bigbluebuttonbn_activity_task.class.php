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
 * @package moodlecore
 * @subpackage backup-moodle2
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/bigbluebuttonbn/backup/moodle2/restore_bigbluebuttonbn_stepslib.php'); // Because it exists (must)

/**
 * bigbluebuttonbn restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 */
class restore_bigbluebuttonbn_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // bigbluebuttonbn only has one structure step
        $this->add_step(new restore_bigbluebuttonbn_activity_structure_step('bigbluebuttonbn_structure', 'bigbluebuttonbn.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    static public function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('bigbluebuttonbn', array('intro'), 'bigbluebuttonbn');
        $contents[] = new restore_decode_content('bigbluebuttonbn_logs', array('log'), 'bigbluebuttonbn_logs');
        
        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    static public function define_decode_rules() {
        $rules = array();

        $rules[] = new restore_decode_rule('BIGBLUEBUTTONBNVIEWBYID', '/mod/bigbluebuttonbn/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('BIGBLUEBUTTONBNINDEX', '/mod/bigbluebuttonbn/index.php?id=$1', 'course');

        return $rules;
    }

    static public function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('bigbluebuttonbn', 'add', 'view.php?id={course_module}', '{bigbluebuttonbn}');
        $rules[] = new restore_log_rule('bigbluebuttonbn', 'update', 'view.php?id={course_module}', '{bigbluebuttonbn}');
        $rules[] = new restore_log_rule('bigbluebuttonbn', 'view', 'view.php?id={course_module}', '{bigbluebuttonbn}');
        $rules[] = new restore_log_rule('bigbluebuttonbn', 'report', 'report.php?id={course_module}', '{bigbluebuttonbn}');
        
        return $rules;
    }

    static public function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('bigbluebuttonbn', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}