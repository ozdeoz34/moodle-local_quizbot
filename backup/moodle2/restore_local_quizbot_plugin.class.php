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
 * Restore of Quizbot for Moodle's own data from a course backup (1.2.3): see backup_local_quizbot_plugin.
 *
 * A course module that a record points to may be restored after the record itself, so the old id is kept, negative,
 * until the whole restore has run; the after_restore methods then put in the new id (or drop what was not restored).
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_quizbot_plugin extends restore_local_plugin {
    /**
     * Quizbot's labels of a question.
     *
     * @return restore_path_element[]
     */
    protected function define_question_plugin_structure() {
        return [new restore_path_element('quizbot_question', $this->get_pathfor('/quizbot_question'))];
    }

    /**
     * One question's labels, given to the restored question. A question that was not created anew (the site already
     * had it) keeps the labels it has.
     *
     * @param array $data
     */
    public function process_quizbot_question($data) {
        global $DB;

        $data = (object) $data;
        $questionid = (int) $this->get_new_parentid('question');
        if (!$questionid) {
            return;
        }
        $entryid = (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $questionid]);
        if (!$entryid || $DB->record_exists('local_quizbot_question', ['questionbankentryid' => $entryid])) {
            return;
        }
        $DB->insert_record('local_quizbot_question', (object) [
            'questionbankentryid' => $entryid,
            'questionid' => $questionid,
            'courseid' => $this->get_task()->get_courseid(),
            'cmid' => -abs((int) $data->cmid),
            'jobid' => 0,
            'userid' => (int) $this->get_mappingid('user', $data->userid, 0),
            'qtype' => $data->qtype,
            'sourcename' => $data->sourcename,
            'sourcecmid' => -abs((int) $data->sourcecmid),
            'topic' => $data->topic,
            'bloom' => $data->bloom,
            'difficulty' => $data->difficulty,
            'edited' => (int) $data->edited,
            'rewritten' => (int) $data->rewritten,
            'timecreated' => (int) $data->timecreated,
        ]);
    }

    /**
     * After the whole restore: the quiz each question was added to, and its material, as restored (0 when not restored).
     */
    protected function after_restore_question() {
        global $DB;

        $rows = $DB->get_records_select(
            'local_quizbot_question',
            'courseid = ? AND (cmid < 0 OR sourcecmid < 0)',
            [$this->get_task()->get_courseid()]
        );
        foreach ($rows as $row) {
            $row->cmid = $row->cmid < 0 ? (int) $this->get_mappingid('course_module', -$row->cmid, 0) : $row->cmid;
            $row->sourcecmid = $row->sourcecmid < 0
                ? (int) $this->get_mappingid('course_module', -$row->sourcecmid, 0) : $row->sourcecmid;
            $DB->update_record('local_quizbot_question', $row);
        }
    }

    /**
     * The remedial quizzes made from a quiz.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [new restore_path_element('quizbot_remedial', $this->get_pathfor('/quizbot_remedial'))];
    }

    /**
     * One remedial quiz made from the quiz being restored.
     *
     * @param array $data
     */
    public function process_quizbot_remedial($data) {
        global $DB;

        $data = (object) $data;
        $DB->insert_record('local_quizbot_remedial', (object) [
            'courseid' => $this->get_task()->get_courseid(),
            'cmid' => $this->get_task()->get_moduleid(),
            'quizcmid' => -abs((int) $data->quizcmid),
            'groupid' => -abs((int) $data->groupid),
            'topic' => $data->topic,
            'userid' => (int) $this->get_mappingid('user', $data->userid, 0),
            'timecreated' => (int) $data->timecreated,
        ]);
    }

    /**
     * After the whole restore: the remedial quiz and its group as restored. A record whose remedial quiz was not
     * restored is dropped; a group that was not restored becomes 0.
     */
    protected function after_restore_module() {
        global $DB;

        $rows = $DB->get_records_select(
            'local_quizbot_remedial',
            'courseid = ? AND (quizcmid < 0 OR groupid < 0)',
            [$this->get_task()->get_courseid()]
        );
        foreach ($rows as $row) {
            if ($row->quizcmid < 0) {
                $row->quizcmid = (int) $this->get_mappingid('course_module', -$row->quizcmid, 0);
                if (!$row->quizcmid) {
                    $DB->delete_records('local_quizbot_remedial', ['id' => $row->id]);
                    continue;
                }
            }
            $row->groupid = $row->groupid < 0 ? (int) $this->get_mappingid('group', -$row->groupid, 0) : $row->groupid;
            $DB->update_record('local_quizbot_remedial', $row);
        }
    }
}
