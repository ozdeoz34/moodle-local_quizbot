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

namespace local_quizbot\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Tests for the plugin's privacy provider: what it says it keeps, what it exports and what it deletes.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass the first teacher */
    private $teacher;

    /** @var \stdClass the second teacher */
    private $other;

    /** @var \context_module the first quiz */
    private $context;

    /** @var \context_module the second quiz */
    private $othercontext;

    /**
     * Two teachers, two quizzes: the first teacher ran the wizard for both, the second for the first quiz only.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->other = $generator->create_and_enrol($course, 'editingteacher');
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $otherquiz = $generator->create_module('quiz', ['course' => $course->id]);
        $this->context = \context_module::instance($quiz->cmid);
        $this->othercontext = \context_module::instance($otherquiz->cmid);
        $run = fn ($userid, $cmid, $text) => $DB->insert_record('local_quizbot_job', (object) [
            'userid' => $userid, 'courseid' => $course->id, 'cmid' => $cmid, 'status' => 'draft',
            'sources' => json_encode(['text' => $text]), 'options' => '{}', 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $first = $run($this->teacher->id, $quiz->cmid, 'First teacher, first quiz.');
        $run($this->teacher->id, $otherquiz->cmid, 'First teacher, second quiz.');
        $run($this->other->id, $quiz->cmid, 'Second teacher, first quiz.');
        get_file_storage()->create_file_from_string([
            'contextid' => $this->context->id, 'component' => 'local_quizbot', 'filearea' => 'upload',
            'itemid' => $first, 'filepath' => '/', 'filename' => 'notes.txt',
        ], 'notes');
    }

    /**
     * The plugin says which table it keeps and that material leaves the site.
     */
    public function test_metadata(): void {
        $items = provider::get_metadata(new collection('local_quizbot'))->get_collection();
        $names = array_map(fn ($item) => $item->get_name(), $items);
        $this->assertContains('local_quizbot_job', $names);
        $this->assertContains('local_quizbot_question', $names);
        $this->assertContains('quizbotserver', $names);
    }

    /**
     * A teacher's contexts are the quizzes the wizard was used for.
     */
    public function test_contexts_and_users(): void {
        $contextids = provider::get_contexts_for_userid($this->teacher->id)->get_contextids();
        $this->assertEqualsCanonicalizing([$this->context->id, $this->othercontext->id], $contextids);
        $this->assertEquals([$this->context->id], provider::get_contexts_for_userid($this->other->id)->get_contextids());

        $userlist = new userlist($this->context, 'local_quizbot');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->teacher->id, $this->other->id], $userlist->get_userids());
    }

    /**
     * The export holds the teacher's own runs only.
     */
    public function test_export(): void {
        $this->export_context_data_for_user($this->teacher->id, $this->context, 'local_quizbot');
        $data = writer::with_context($this->context)->get_data([get_string('privacy:path', 'local_quizbot')]);
        $this->assertCount(1, $data->runs);
        $this->assertStringContainsString('First teacher, first quiz.', $data->runs[0]->sources);
    }

    /**
     * Deleting a quiz's data removes every teacher's runs for it, and their files.
     */
    public function test_delete_for_a_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context($this->context);
        $this->assertEquals(0, $DB->count_records('local_quizbot_job', ['cmid' => $this->context->instanceid]));
        $this->assertEquals(1, $DB->count_records('local_quizbot_job'));
        $this->assertCount(0, get_file_storage()->get_area_files(
            $this->context->id,
            'local_quizbot',
            'upload',
            false,
            'id',
            false
        ));
    }

    /**
     * Deleting one teacher's data leaves the other teacher's.
     */
    public function test_delete_for_a_user(): void {
        global $DB;

        provider::delete_data_for_user(new approved_contextlist($this->teacher, 'local_quizbot', [$this->context->id,
            $this->othercontext->id]));
        $this->assertEquals(0, $DB->count_records('local_quizbot_job', ['userid' => $this->teacher->id]));
        $this->assertEquals(1, $DB->count_records('local_quizbot_job', ['userid' => $this->other->id]));
    }

    /**
     * Deleting several users' data in one quiz.
     */
    public function test_delete_for_users(): void {
        global $DB;

        provider::delete_data_for_users(new approved_userlist($this->context, 'local_quizbot', [$this->other->id]));
        $this->assertEquals(0, $DB->count_records('local_quizbot_job', ['userid' => $this->other->id]));
        $this->assertEquals(2, $DB->count_records('local_quizbot_job', ['userid' => $this->teacher->id]));
    }

    /**
     * The questions a teacher added with Quizbot are found by course and exported. Deleting takes the teacher (and the
     * name of the material) out of the record; the questions keep their labels for Quizbot Analysis.
     */
    public function test_added_questions(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $add = fn ($userid, $entry) => $DB->insert_record('local_quizbot_question', (object) [
            'questionbankentryid' => $entry, 'questionid' => $entry, 'courseid' => $course->id,
            'cmid' => $this->context->instanceid, 'userid' => $userid, 'qtype' => 'essay', 'sourcename' => 'Notes.pdf',
            'topic' => 'Cells', 'bloom' => 'evaluate', 'difficulty' => 'hard', 'timecreated' => time(),
        ]);
        $add($this->teacher->id, 1001);
        $add($this->teacher->id, 1002);
        $add($this->other->id, 1003);

        $this->assertContainsEquals($coursecontext->id, provider::get_contexts_for_userid($this->teacher->id)->get_contextids());
        $userlist = new userlist($coursecontext, 'local_quizbot');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->teacher->id, $this->other->id], $userlist->get_userids());

        $this->export_context_data_for_user($this->teacher->id, $coursecontext, 'local_quizbot');
        $data = writer::with_context($coursecontext)->get_data([get_string('privacy:pathquestions', 'local_quizbot')]);
        $this->assertCount(2, $data->questions);
        $this->assertSame('evaluate', $data->questions[0]->bloom);

        // One teacher.
        provider::delete_data_for_user(new approved_contextlist($this->teacher, 'local_quizbot', [$coursecontext->id]));
        $this->assertEquals(3, $DB->count_records('local_quizbot_question'));
        $this->assertEquals(0, $DB->count_records('local_quizbot_question', ['userid' => $this->teacher->id]));
        $this->assertEquals(2, $DB->count_records_select(
            'local_quizbot_question',
            "userid = 0 AND sourcename = '' AND bloom = 'evaluate' AND topic = 'Cells'"
        ));
        $this->assertEquals(1, $DB->count_records('local_quizbot_question', ['userid' => $this->other->id]));

        // Several users; then everyone in the course; a quiz's deletion leaves the records alone.
        provider::delete_data_for_users(new approved_userlist($coursecontext, 'local_quizbot', [$this->other->id]));
        $this->assertEquals(0, $DB->count_records_select('local_quizbot_question', 'userid > 0'));
        $add($this->other->id, 1004);
        provider::delete_data_for_all_users_in_context($this->context);
        $this->assertEquals(1, $DB->count_records_select('local_quizbot_question', 'userid > 0'));
        provider::delete_data_for_all_users_in_context($coursecontext);
        $this->assertEquals(0, $DB->count_records_select('local_quizbot_question', 'userid > 0'));
        $this->assertEquals(4, $DB->count_records('local_quizbot_question'));
    }
}
