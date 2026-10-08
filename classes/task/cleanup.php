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

namespace local_quizbot\task;

use local_quizbot\local\job;

/**
 * Daily: removes Quizbot wizard runs nobody will come back to, with the files uploaded or fetched for them. What a
 * run holds (the chosen sources, pasted text, the questions not added) is only needed while the teacher works on it;
 * questions that were added live on in the quiz as ordinary Moodle questions. Also removes what Quizbot kept about
 * added questions (their topic, Bloom's level and difficulty) once a question is deleted.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup extends \core\task\scheduled_task {
    /** @var array run status => days after its last change when it is removed */
    public const KEEP_DAYS = [
        job::STATUS_SAVED => 7,
        job::STATUS_DISCARDED => 7,
        job::STATUS_RUNNING => 2, // Quizbot gives up on a request long before this.
        job::STATUS_REVIEW => 30, // Questions written but never added: Quizbot has forgotten the text by then.
        job::STATUS_DRAFT => 60,
    ];

    /**
     * Name shown in the scheduled tasks list.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_cleanup', 'local_quizbot');
    }

    /**
     * Removes the old runs.
     */
    public function execute(): void {
        global $DB;

        $fs = get_file_storage();
        $removed = 0;
        foreach (self::KEEP_DAYS as $status => $days) {
            $old = $DB->get_records_select(
                'local_quizbot_job',
                'status = ? AND timemodified < ?',
                [$status, time() - $days * DAYSECS],
                'id',
                'id, cmid'
            );
            foreach ($old as $run) {
                // The quiz may be gone already - then Moodle has removed its files with it.
                if ($context = \context_module::instance($run->cmid, IGNORE_MISSING)) {
                    foreach (['upload', 'link'] as $area) {
                        $fs->delete_area_files($context->id, 'local_quizbot', $area, $run->id);
                    }
                }
                $DB->delete_records('local_quizbot_job', ['id' => $run->id]);
                $removed++;
            }
        }
        mtrace('Quizbot: removed ' . $removed . ' old wizard run(s).');

        // The labels of questions that are no longer in any question bank (deleted, or gone with their course).
        $gone = 'NOT EXISTS (SELECT 1 FROM {question_bank_entries} e WHERE e.id = {local_quizbot_question}.questionbankentryid)';
        $labels = $DB->count_records_select('local_quizbot_question', $gone);
        if ($labels) {
            $DB->delete_records_select('local_quizbot_question', $gone);
        }
        mtrace('Quizbot: removed the labels of ' . $labels . ' deleted question(s).');

        // The record of remedial quizzes that have been deleted (or gone with their course).
        $gone = 'NOT EXISTS (SELECT 1 FROM {course_modules} cm WHERE cm.id = {local_quizbot_remedial}.quizcmid)';
        $remedials = $DB->count_records_select('local_quizbot_remedial', $gone);
        if ($remedials) {
            $DB->delete_records_select('local_quizbot_remedial', $gone);
        }
        mtrace('Quizbot: forgot ' . $remedials . ' deleted remedial quiz(zes).');
    }
}
