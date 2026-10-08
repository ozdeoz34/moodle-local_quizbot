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

use local_quizbot\local\student;

/**
 * Tests for a student's own results: the card on an attempt's review page and "My progress".
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\local\student
 */
final class student_test extends \advanced_testcase {
    /**
     * A question Quizbot wrote.
     *
     * @param int $entry its question bank entry
     * @param string $source the material
     * @param string $bloom
     * @return array as analysis::questions()
     */
    private static function q(int $entry, string $source, string $bloom = 'remember'): array {
        return ['text' => 'Question ' . $entry, 'name' => 'Q', 'qtype' => 'truefalse', 'entry' => $entry, 'quizbot' => true,
            'source' => $source, 'sourcecmid' => 0, 'topic' => '', 'bloom' => $bloom, 'difficulty' => 'easy'];
    }

    /**
     * The word for a result.
     */
    public function test_result(): void {
        $this->assertSame('strong', student::result([1, 1, 1, 0])['word']);
        $this->assertSame('getting', student::result([1, 0])['word']);
        $this->assertSame('revise', student::result([1, 0, 0, 0])['word']);
        $this->assertSame(1, student::result([0.5, 0.4])['right'], 'Half the marks or more counts as right.');
    }

    /**
     * The card: topics weakest first, the topic to revise, essays to be graded left out, no card topic for questions
     * Quizbot did not write.
     */
    public function test_card(): void {
        $questions = [1 => self::q(1, 'Light'), 2 => self::q(2, 'Light'), 3 => self::q(3, 'Calvin'), 4 => self::q(4, 'Calvin'),
            5 => ['quizbot' => false, 'entry' => 5] + self::q(5, ''), 6 => self::q(6, 'Calvin')];
        $answers = [['slot' => 1, 'question' => 1, 'mark' => 1.0], ['slot' => 2, 'question' => 2, 'mark' => 1.0],
            ['slot' => 3, 'question' => 3, 'mark' => 0.0], ['slot' => 4, 'question' => 4, 'mark' => 1.0],
            ['slot' => 5, 'question' => 5, 'mark' => 0.0], ['slot' => 6, 'question' => 6, 'mark' => null]];
        $card = student::card($answers, $questions);
        $this->assertSame(['Calvin', 'Light'], array_column($card['topics'], 'name'));
        $this->assertSame([1, 2], array_column($card['topics'], 'right'));
        $this->assertSame('Calvin', $card['revise']['name']);
        $this->assertSame(1, $card['pending']);

        $strong = student::card([['slot' => 1, 'question' => 1, 'mark' => 1.0]], $questions);
        $this->assertNull($strong['revise'], 'Nothing to revise when every topic went well.');
    }

    /**
     * "My progress": the latest answer counts, hidden results stay hidden, the next quiz, questions to look at again.
     */
    public function test_progress(): void {
        $now = 1000000;
        $quizzes = [
            ['id' => 1, 'cmid' => 11, 'name' => 'Cells', 'timeopen' => 0, 'timeclose' => 0, 'sumgrades' => 2.0],
            ['id' => 2, 'cmid' => 12, 'name' => 'Light', 'timeopen' => 0, 'timeclose' => 0, 'sumgrades' => 2.0],
            ['id' => 3, 'cmid' => 13, 'name' => 'Enzymes', 'timeopen' => $now + 86400, 'timeclose' => 0, 'sumgrades' => 2.0],
            ['id' => 4, 'cmid' => 14, 'name' => 'Closed', 'timeopen' => 0, 'timeclose' => $now - 1, 'sumgrades' => 2.0],
        ];
        $attempts = [
            ['id' => 100, 'quiz' => 1, 'usage' => 500, 'sumgrades' => 0.0, 'timefinish' => 10, 'visible' => true],
            ['id' => 101, 'quiz' => 1, 'usage' => 501, 'sumgrades' => 2.0, 'timefinish' => 20, 'visible' => true],
            ['id' => 102, 'quiz' => 2, 'usage' => 502, 'sumgrades' => 1.0, 'timefinish' => 30, 'visible' => false],
        ];
        $questions = [1 => self::q(1, 'Cell parts', 'remember'), 2 => self::q(2, 'Membranes', 'apply')];
        // Question 1 wrong, then right; question 2 wrong both times. The hidden attempt's answers are not given.
        $answers = [
            ['usage' => 500, 'slot' => 1, 'question' => 1, 'mark' => 0.0],
            ['usage' => 500, 'slot' => 2, 'question' => 2, 'mark' => 0.0],
            ['usage' => 501, 'slot' => 1, 'question' => 1, 'mark' => 1.0],
            ['usage' => 501, 'slot' => 2, 'question' => 2, 'mark' => 0.0],
        ];
        $p = student::progress($quizzes, $attempts, $answers, $questions, $now);

        $this->assertSame(4, $p['quizcount']);
        $this->assertSame(2, $p['done']);
        $this->assertSame('Enzymes', $p['next']['name'], 'Not done, and opens tomorrow; the closed one is skipped.');
        $this->assertEqualsWithDelta(100.0, $p['average'], 0.001, 'The latest attempt at Cells; Light is hidden.');
        $this->assertSame([null, 100.0], array_map(
            fn ($d) => $d['pct'] === null ? null : round($d['pct'], 1),
            array_reverse($p['quizzes'])
        ));
        $this->assertSame(['Membranes', 'Cell parts'], array_column($p['topics'], 'name'));
        $this->assertSame('Cell parts', $p['strongest']['name']);
        $this->assertSame('Membranes', $p['revise']['name']);
        $this->assertSame(['remember', 'apply'], array_column($p['skills'], 'level'));
        $this->assertCount(1, $p['again']);
        $this->assertSame(101, $p['again'][0]['attempt'], 'The latest wrong answer, from the latest attempt.');
    }

    /**
     * A real quiz: the card shows a finished attempt's topics, and nothing while the quiz hides the marks.
     */
    public function test_card_follows_review_options(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id, 'questionsperpage' => 0, 'grade' => 10]);
        $questions = $generator->get_plugin_generator('core_question');
        $category = $questions->create_question_category(['contextid' => \context_module::instance($quiz->cmid)->id]);
        $question = $questions->create_question('truefalse', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);
        if (class_exists(\mod_quiz\quiz_settings::class)) {
            \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        } else {
            quiz_update_sumgrades($quiz);
        }
        $DB->insert_record('local_quizbot_question', (object) [
            'questionbankentryid' => $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id]),
            'questionid' => $question->id, 'courseid' => $course->id, 'cmid' => $quiz->cmid, 'userid' => 2,
            'qtype' => 'truefalse', 'sourcename' => 'Light (Word)', 'sourcecmid' => 0, 'topic' => 'Light',
            'bloom' => 'remember', 'difficulty' => 'easy', 'timecreated' => time(),
        ]);
        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);
        $quizgenerator->submit_responses($attempt->id, [1 => 'False'], false, true);

        [$course, $cm] = get_course_and_cm_from_cmid($quiz->cmid, 'quiz');
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attempt->id]);
        $card = student::gather_card($attempt, $DB->get_record('quiz', ['id' => $quiz->id]), $cm);
        $this->assertSame('Light (Word)', $card['topics'][0]['name']);
        $this->assertSame('revise', $card['topics'][0]['word']);

        // The teacher hides marks from students at every stage: no card, and "My progress" leaves the quiz's score out.
        $DB->set_field('quiz', 'reviewmarks', 0, ['id' => $quiz->id]);
        $this->assertNull(student::gather_card($attempt, $DB->get_record('quiz', ['id' => $quiz->id]), $cm));
        $progress = student::gather_progress($course, $student->id);
        $this->assertSame(1, $progress['done']);
        $this->assertNull($progress['quizzes'][0]['pct']);
        $this->assertSame([], $progress['topics']);
    }
}
