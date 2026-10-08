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

use local_quizbot\local\source_finder;

/**
 * Tests for the class that lists what Quizbot can read in a course.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\local\source_finder
 */
final class source_finder_test extends \advanced_testcase {
    /**
     * YouTube videos are recognised in every way a teacher links or embeds them.
     *
     * @dataProvider youtube_provider
     * @param string $html
     * @param array $expected
     */
    public function test_youtube_ids(string $html, array $expected): void {
        $this->assertSame($expected, source_finder::youtube_ids($html));
    }

    /**
     * Cases for test_youtube_ids.
     *
     * @return array
     */
    public static function youtube_provider(): array {
        return [
            'watch address' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', ['dQw4w9WgXcQ']],
            'short address with a time' => ['https://youtu.be/dQw4w9WgXcQ?t=42', ['dQw4w9WgXcQ']],
            'embedded player' => ['<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe>', ['dQw4w9WgXcQ']],
            'v is not the first parameter' => ['https://www.youtube.com/watch?feature=share&amp;v=dQw4w9WgXcQ', ['dQw4w9WgXcQ']],
            'the same video twice' => ['https://youtu.be/dQw4w9WgXcQ and https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                ['dQw4w9WgXcQ']],
            'a playlist is not a video' => ['https://www.youtube.com/playlist?list=PL590L5WQmH8fJ54F369BLDSqIwcs-TCfs', []],
            'no video' => ['<p>Just text.</p>', []],
        ];
    }

    /**
     * A page, a linked video and a quiz with questions are listed; an empty quiz and a forum are not.
     */
    public function test_for_course_lists_what_can_be_read(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Photosynthesis',
            'content' => '<p>' . str_repeat('Plants make glucose from light, water and carbon dioxide. ', 8) . '</p>']);
        $generator->create_module('url', ['course' => $course->id, 'name' => 'A video',
            'externalurl' => 'https://youtu.be/dQw4w9WgXcQ']);
        $generator->create_module('forum', ['course' => $course->id, 'name' => 'Talk']);
        $empty = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Empty quiz']);
        $full = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz with a question']);
        $questions = $generator->get_plugin_generator('core_question');
        $category = $questions->create_question_category(['contextid' => \context_module::instance($full->cmid)->id]);
        $question = $questions->create_question('truefalse', null, ['category' => $category->id,
            'questiontext' => ['text' => 'The sky is blue.']]);
        quiz_add_quiz_question($question->id, $full);

        $items = source_finder::by_key(source_finder::for_course(get_course($course->id), (int) $empty->cmid));

        $this->assertArrayHasKey('t' . $page->cmid, $items);
        $this->assertSame('page', $items['t' . $page->cmid]['kind']);
        $this->assertArrayHasKey('ydQw4w9WgXcQ', $items);
        $this->assertSame('youtube', $items['ydQw4w9WgXcQ']['kind']);
        $this->assertArrayHasKey('q' . $full->cmid, $items);
        $this->assertSame('quiz', $items['q' . $full->cmid]['kind']);
        $this->assertArrayNotHasKey('q' . $empty->cmid, $items, 'A quiz without questions has nothing to offer.');
        $this->assertCount(3, $items, 'The forum is not a source.');

        // What is sent for the quiz: its questions with their answers, as text.
        [, $cm] = get_course_and_cm_from_cmid($full->cmid, 'quiz');
        $text = source_finder::quiz_text($cm);
        $this->assertStringContainsString('The sky is blue.', $text);
        $this->assertStringContainsString('[correct]', $text);
        // What is sent for the page: its text without the markup.
        [, $pagecm] = get_course_and_cm_from_cmid($page->cmid, 'page');
        $this->assertStringStartsWith('Plants make glucose', source_finder::text_for($pagecm));
    }

    /**
     * A teacher is not offered a quiz he or she may not edit.
     */
    public function test_quiz_needs_the_right_to_manage_it(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $this->setAdminUser();
        $questions = $generator->get_plugin_generator('core_question');
        $category = $questions->create_question_category(['contextid' => \context_module::instance($quiz->cmid)->id]);
        quiz_add_quiz_question($questions->create_question('truefalse', null, ['category' => $category->id])->id, $quiz);

        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->assertSame([], source_finder::by_key(source_finder::for_course(get_course($course->id))));

        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $this->assertArrayHasKey('q' . $quiz->cmid, source_finder::by_key(source_finder::for_course(get_course($course->id))));
    }
}
