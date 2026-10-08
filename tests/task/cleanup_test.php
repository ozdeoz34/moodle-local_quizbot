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

/**
 * Tests for the daily clean-up of wizard runs, and for putting a file into the course.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\task\cleanup
 * @covers     \local_quizbot\local\course_files
 */
final class cleanup_test extends \advanced_testcase {
    /**
     * Old runs go, with their files; runs a teacher may still come back to stay.
     */
    public function test_old_runs_are_removed(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $context = \context_module::instance($quiz->cmid);
        $cases = [   // Status, days since the last change, should it go?
            ['saved', 8, true], ['saved', 3, false], ['discarded', 9, true], ['review', 31, true], ['review', 5, false],
            ['draft', 61, true], ['draft', 30, false], ['running', 3, true],
        ];
        $ids = [];
        foreach ($cases as [$status, $days]) {
            $ids[] = $DB->insert_record('local_quizbot_job', (object) ['userid' => 2, 'courseid' => $course->id,
                'cmid' => $quiz->cmid,
                'status' => $status, 'sources' => '{}', 'options' => '{}', 'timecreated' => time() - $days * DAYSECS,
                'timemodified' => time() - $days * DAYSECS]);
        }
        $fs = get_file_storage();
        foreach ([0, 1] as $i) {
            $fs->create_file_from_string(['contextid' => $context->id, 'component' => 'local_quizbot', 'filearea' => 'upload',
                'itemid' => $ids[$i], 'filepath' => '/', 'filename' => 'notes.txt'], 'notes');
        }

        ob_start();
        (new cleanup())->execute();
        ob_end_clean();

        foreach ($cases as $i => [$status, $days, $gone]) {
            $this->assertSame(!$gone, $DB->record_exists('local_quizbot_job', ['id' => $ids[$i]]), "$status, $days days old");
        }
        $this->assertCount(
            0,
            $fs->get_area_files($context->id, 'local_quizbot', 'upload', $ids[0], 'id', false),
            'The removed run\'s file.'
        );
        $this->assertCount(
            1,
            $fs->get_area_files($context->id, 'local_quizbot', 'upload', $ids[1], 'id', false),
            'The kept run\'s file.'
        );
    }

    /**
     * A run whose quiz is gone is removed without an error.
     */
    public function test_run_of_a_deleted_quiz(): void {
        global $DB;

        $this->resetAfterTest();
        $id = $DB->insert_record('local_quizbot_job', (object) ['userid' => 2, 'courseid' => 1, 'cmid' => 999999,
            'status' => 'saved',
            'timecreated' => time() - 30 * DAYSECS, 'timemodified' => time() - 30 * DAYSECS]);
        ob_start();
        (new cleanup())->execute();
        ob_end_clean();
        $this->assertFalse($DB->record_exists('local_quizbot_job', ['id' => $id]));
    }

    /**
     * The labels of a deleted question go; those of a question still in a bank stay.
     */
    public function test_labels_of_deleted_questions(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $questions = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questions->create_question_category(['contextid' => \context_module::instance($quiz->cmid)->id]);
        $entries = [];
        foreach (['kept', 'deleted'] as $name) {
            $question = $questions->create_question('shortanswer', null, ['category' => $category->id, 'name' => $name]);
            $entries[$name] = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id]);
            $DB->insert_record('local_quizbot_question', (object) ['questionbankentryid' => $entries[$name],
                'questionid' => $question->id, 'courseid' => $course->id, 'cmid' => $quiz->cmid, 'userid' => 2,
                'qtype' => 'shortanswer', 'bloom' => 'remember', 'timecreated' => time()]);
            if ($name === 'deleted') {
                question_delete_question($question->id);
            }
        }

        ob_start();
        (new cleanup())->execute();
        ob_end_clean();

        $this->assertTrue($DB->record_exists('local_quizbot_question', ['questionbankentryid' => $entries['kept']]));
        $this->assertFalse($DB->record_exists('local_quizbot_question', ['questionbankentryid' => $entries['deleted']]));
    }

    /**
     * A file from outside the course becomes a File activity in the quiz's section, hidden from students.
     */
    public function test_file_into_the_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'section' => 2]);
        $context = \context_module::instance($quiz->cmid);
        get_file_storage()->create_file_from_string(['contextid' => $context->id, 'component' => 'local_quizbot',
            'filearea' => 'upload',
            'itemid' => 5, 'filepath' => '/', 'filename' => 'Lesson notes.pdf'], '%PDF-1.4 test');
        get_file_storage()->create_file_from_string(['contextid' => $context->id, 'component' => 'local_quizbot',
            'filearea' => 'link',
            'itemid' => 5, 'filepath' => '/', 'filename' => 'from-a-link.pdf'], '%PDF-1.4 test');

        $candidates = \local_quizbot\local\course_files::candidates($context, 5);
        $this->assertCount(2, $candidates);
        $this->assertTrue(\local_quizbot\local\course_files::can_add($course));

        [, $cm] = get_course_and_cm_from_cmid($quiz->cmid, 'quiz');
        $file = reset($candidates);
        $newcmid = \local_quizbot\local\course_files::add($course, $cm, $file);

        $new = get_fast_modinfo($course->id)->get_cm($newcmid);
        $this->assertSame('resource', $new->modname);
        $this->assertEquals(0, $new->visible, 'Hidden from students.');
        $this->assertEquals(2, $new->sectionnum, 'In the quiz\'s own section.');
        $stored = get_file_storage()->get_area_files(
            \context_module::instance($newcmid)->id,
            'mod_resource',
            'content',
            0,
            'id',
            false
        );
        $this->assertCount(1, $stored);
        $this->assertSame($file->get_filename(), reset($stored)->get_filename());

        // A student may not add activities, so is not offered this.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->assertFalse(\local_quizbot\local\course_files::can_add($course));
    }
}
