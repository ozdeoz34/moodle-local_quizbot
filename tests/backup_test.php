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

namespace local_quizbot;

use local_quizbot\local\question_saver;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Backup and restore of the plugin's data with a course (1.2.3).
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_local_quizbot_plugin
 * @covers     \restore_local_quizbot_plugin
 */
final class backup_test extends \advanced_testcase {
    /**
     * Backs a course up and restores it as a new course.
     *
     * @param \stdClass $course
     * @return int the new course's id
     */
    private function backup_and_restore(\stdClass $course): int {
        global $CFG, $USER;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $file = $bc->get_results()['backup_destination'];
        $folder = 'quizbot_backup_test_' . random_string(6);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $CFG->tempdir . '/backup/' . $folder);
        $bc->destroy();

        $newid = \restore_dbops::create_new_course('Restored', 'RESTORED', $course->category);
        $rc = new \restore_controller(
            $folder,
            $newid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return (int) $newid;
    }

    /**
     * A course with a quiz of Quizbot questions (labelled, the material a page of the course) and a remedial quiz made
     * from it, for a group: after a backup and restore the new course's questions carry the same labels, pointing at
     * the new quiz and the new page, and the new quiz has its remedial quiz and group; the old course is untouched.
     */
    public function test_backup_and_restore(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', ['course' => $course->id, 'name' => 'Calvin notes']);
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'name' => 'Unit test']);
        [$course, $cm] = get_course_and_cm_from_cmid($quiz->cmid, 'quiz');
        $questions = [
            ['type' => 'truefalse', 'text' => 'RuBisCO fixes carbon dioxide.', 'answer' => true, 'feedback' => '',
                'source' => 's1', 'topic' => 'Carbon fixation', 'bloom' => 'remember', 'difficulty' => 'easy'],
            ['type' => 'shortanswer', 'text' => 'Name the enzyme that fixes carbon dioxide.', 'answers' => ['RuBisCO'],
                'feedback' => '', 'source' => 's1', 'topic' => 'Carbon fixation enzyme', 'bloom' => 'understand',
                'difficulty' => 'medium'],
        ];
        question_saver::save($questions, $course, $cm, 'quiz', 0, ['s1' => 'Calvin notes']);
        $DB->set_field('local_quizbot_question', 'sourcecmid', $page->cmid, ['courseid' => $course->id]);
        $this->assertSame(2, $DB->count_records('local_quizbot_question', ['courseid' => $course->id]));

        $remedial = $gen->create_module('quiz', ['course' => $course->id, 'name' => 'Extra practice: Carbon fixation']);
        $group = $gen->create_group(['courseid' => $course->id, 'name' => 'Remedial: Carbon fixation']);
        $DB->insert_record('local_quizbot_remedial', (object) ['courseid' => $course->id, 'cmid' => $quiz->cmid,
            'quizcmid' => $remedial->cmid, 'groupid' => $group->id, 'topic' => 'Carbon fixation',
            'userid' => get_admin()->id, 'timecreated' => time()]);

        $newid = $this->backup_and_restore($course);

        $newcms = get_fast_modinfo($newid)->get_cms();
        $byname = [];
        foreach ($newcms as $newcm) {
            $byname[$newcm->name] = (int) $newcm->id;
        }
        $labels = $DB->get_records('local_quizbot_question', ['courseid' => $newid], 'topic');
        $this->assertSame(['Carbon fixation', 'Carbon fixation enzyme'], array_values(array_column($labels, 'topic')));
        $this->assertSame(['remember', 'understand'], array_values(array_column($labels, 'bloom')));
        foreach ($labels as $label) {
            $this->assertSame($byname['Unit test'], (int) $label->cmid, 'The quiz the question is in, in the new course.');
            $this->assertSame($byname['Calvin notes'], (int) $label->sourcecmid, 'Its material, in the new course.');
            $this->assertSame('Calvin notes', $label->sourcename);
            $this->assertTrue($DB->record_exists('question_versions', ['questionid' => $label->questionid,
                'questionbankentryid' => $label->questionbankentryid]), 'Points at the restored question.');
        }
        $this->assertSame(0, $DB->count_records_select('local_quizbot_question', 'cmid < 0 OR sourcecmid < 0'));

        $restored = $DB->get_record('local_quizbot_remedial', ['courseid' => $newid], '*', MUST_EXIST);
        $this->assertSame($byname['Unit test'], (int) $restored->cmid);
        $this->assertSame($byname['Extra practice: Carbon fixation'], (int) $restored->quizcmid);
        $this->assertSame('Remedial: Carbon fixation', $DB->get_field('groups', 'name', ['id' => $restored->groupid,
            'courseid' => $newid]));
        $this->assertSame('Carbon fixation', $restored->topic);

        // The old course keeps its own.
        $this->assertSame(2, $DB->count_records('local_quizbot_question', ['courseid' => $course->id]));
        $this->assertTrue($DB->record_exists('local_quizbot_remedial', ['courseid' => $course->id,
            'quizcmid' => $remedial->cmid]));
    }
}
