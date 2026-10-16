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
 * Backup of Quizbot for Moodle's own data in a course backup (1.2.3).
 *
 * Two kinds of data belong to a course:
 * - with each question: what Quizbot found out about it (local_quizbot_question: topic, skill, difficulty, the material it
 *   was written from), so Quizbot Analysis of a restored or duplicated course still knows its questions;
 * - with each quiz: the remedial quizzes made from it (local_quizbot_remedial), so the restored quiz's analysis still
 *   shows them and its class menu still leaves their groups out.
 * The teachers' wizard runs (local_quizbot_job) are not backed up: they are drafts that last until the questions are
 * added or discarded, and the scheduled task removes them.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_quizbot_plugin extends backup_local_plugin {
    /**
     * Quizbot's labels of a question, kept with the question.
     *
     * @return backup_plugin_element
     */
    protected function define_question_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $label = new backup_nested_element('quizbot_question', ['id'], [
            'cmid', 'userid', 'qtype', 'sourcename', 'sourcecmid', 'topic', 'bloom', 'difficulty', 'edited', 'rewritten',
            'timecreated',
        ]);
        $wrapper->add_child($label);
        $label->set_source_table('local_quizbot_question', ['questionid' => backup::VAR_PARENTID]);
        $label->annotate_ids('user', 'userid');

        return $plugin;
    }

    /**
     * The remedial quizzes made from a quiz, kept with that quiz.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($wrapper);

        $remedial = new backup_nested_element('quizbot_remedial', ['id'], [
            'quizcmid', 'groupid', 'topic', 'userid', 'timecreated',
        ]);
        $wrapper->add_child($remedial);
        $remedial->set_source_table('local_quizbot_remedial', ['cmid' => backup::VAR_MODID]);
        $remedial->annotate_ids('user', 'userid');
        $remedial->annotate_ids('group', 'groupid');

        return $plugin;
    }
}
