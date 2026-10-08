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

use local_quizbot\local\analysis;

/**
 * Tests for Quizbot Analysis of a quiz: the sums on made-up results, and one quiz with real attempts.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\local\analysis
 */
final class analysis_test extends \advanced_testcase {
    /**
     * A class of ten: user n scores n/10 on every question, so the stronger students do better on each.
     *
     * @param array $questions question id => labels
     * @param int $slots
     * @return array [attempts, answers]
     */
    private static function graded_class(array $questions, int $slots): array {
        $attempts = [];
        $answers = [];
        for ($user = 1; $user <= 10; $user++) {
            $attempts[$user] = ['attemptid' => 100 + $user, 'usage' => 200 + $user, 'sumgrades' => $slots * $user / 10,
                'timefinish' => 1000 + $user];
            for ($slot = 1; $slot <= $slots; $slot++) {
                $answers[] = ['user' => $user, 'slot' => $slot, 'question' => $slot, 'max' => 1.0, 'mark' => $user / 10,
                    'response' => ''];
            }
        }
        return [$attempts, $answers];
    }

    /**
     * Labels of a question Quizbot wrote.
     *
     * @param string $qtype
     * @param string $source
     * @param string $bloom
     * @param string $difficulty
     * @return array
     */
    private static function label(string $qtype, string $source, string $bloom, string $difficulty): array {
        return ['text' => 'A question', 'name' => 'A question', 'qtype' => $qtype, 'quizbot' => true, 'source' => $source,
            'topic' => 'A topic', 'bloom' => $bloom, 'difficulty' => $difficulty];
    }

    /**
     * Scores, average, middle score, pass mark, bands.
     */
    public function test_summary(): void {
        $questions = [1 => self::label('truefalse', 'Light', 'remember', 'easy'),
            2 => self::label('truefalse', 'Light', 'understand', 'medium')];
        [$attempts, $answers] = self::graded_class($questions, 2);
        $a = analysis::compute(12, $attempts, $answers, $questions, 2.0, null);

        $this->assertSame(12, $a['classsize']);
        $this->assertSame(10, $a['finished']);
        $this->assertEqualsWithDelta(55.0, $a['average'], 0.001);
        $this->assertEqualsWithDelta(55.0, $a['median'], 0.001);
        $this->assertTrue($a['passdefault']);
        $this->assertEquals(analysis::DEFAULT_PASS, $a['pass']);
        $this->assertSame(5, $a['passed'], '60% to 100%');
        $this->assertCount(5, $a['below'], '10% to 50%');
        $this->assertSame([1, 2, 2, 2, 3], array_column($a['bands'], 'n'));
        $this->assertSame([true, true, true, false, false], array_column($a['bands'], 'below'));
        $this->assertEqualsWithDelta(10.0, $a['below'][0]['score'], 0.001, 'Lowest first.');

        // The quiz's own "Grade to pass" wins.
        $this->assertSame(9, analysis::compute(12, $attempts, $answers, $questions, 2.0, 20.0)['passed']);
    }

    /**
     * Only the first finished attempt counts, an essay not yet graded is left out, and an unanswered question is 0.
     */
    public function test_marks(): void {
        $this->assertNull(analysis::mark('needsgrading', null));
        $this->assertSame(0.0, analysis::mark('gaveup', null));
        $this->assertSame(0.5, analysis::mark('gradedpartial', '0.5'));
        $this->assertSame(0.0, analysis::mark('gradedwrong', '-0.3333'), 'A penalty never takes a mark below 0.');

        $questions = [1 => self::label('essay', 'Light', 'evaluate', 'hard')];
        $attempts = [1 => ['attemptid' => 1, 'usage' => 1, 'sumgrades' => null, 'timefinish' => 1]];
        $answers = [['user' => 1, 'slot' => 1, 'question' => 1, 'max' => 1.0, 'mark' => null, 'response' => '']];
        $a = analysis::compute(1, $attempts, $answers, $questions, 1.0, null);
        $this->assertSame(1, $a['pending']);
        $this->assertSame(0, $a['graded']);
        $this->assertNull($a['average']);
        $this->assertSame([['key' => 'pending', 'n' => 1]], $a['questions'][0]['notes']);
    }

    /**
     * Topics weakest first, with the questions Quizbot did not write as one group; skills; planned difficulty.
     */
    public function test_topics_skills_difficulty(): void {
        $questions = [
            1 => self::label('truefalse', 'Light', 'remember', 'easy'),
            2 => self::label('truefalse', 'Light', 'remember', 'easy'),
            3 => self::label('truefalse', 'Light', 'remember', 'easy'),
            4 => self::label('multichoice', 'Calvin cycle', 'apply', 'hard'),
            5 => ['text' => 'Mine', 'name' => 'Mine', 'qtype' => 'shortanswer', 'quizbot' => false, 'source' => '',
                'topic' => '', 'bloom' => '', 'difficulty' => ''],
        ];
        $attempts = [];
        $answers = [];
        foreach (range(1, 10) as $user) {
            $attempts[$user] = ['attemptid' => $user, 'usage' => $user, 'sumgrades' => 3.0, 'timefinish' => 1];
            foreach ([1 => 1.0, 2 => 1.0, 3 => 0.9, 4 => $user <= 3 ? 1.0 : 0.0, 5 => 0.5] as $slot => $mark) {
                $answers[] = ['user' => $user, 'slot' => $slot, 'question' => $slot, 'max' => 1.0, 'mark' => $mark,
                    'response' => ''];
            }
        }
        $a = analysis::compute(10, $attempts, $answers, $questions, 5.0, null);

        $this->assertSame(['Calvin cycle', '', 'Light'], array_column($a['topics'], 'name'));
        $this->assertSame([true, true, false], array_column($a['topics'], 'few'), 'Fewer than 3 questions: not certain.');
        $this->assertTrue($a['topics'][1]['other']);
        $this->assertEqualsWithDelta(30.0, $a['topics'][0]['pct'], 0.001);

        $this->assertSame(['remember', 'apply', ''], array_column($a['skills'], 'level'));
        $this->assertEqualsWithDelta(96.667, $a['skills'][0]['pct'], 0.001);

        $this->assertSame(['easy', 'hard'], array_column($a['difficulty'], 'level'));
        $this->assertSame('asplanned', $a['difficulty'][0]['verdict']);
        $this->assertSame('asplanned', $a['difficulty'][1]['verdict']);
        $this->assertSame('harder', analysis::verdict('easy', 60, false));
        $this->assertSame('easier', analysis::verdict('hard', 70, false));
        $this->assertSame('asplanned', analysis::verdict('hard', 70, true), 'One question is held to wider bounds.');
    }

    /**
     * Notes on a question: only with enough answers; very easy, few right, does not separate, a shared wrong answer.
     */
    public function test_notes(): void {
        $questions = [1 => self::label('multichoice', 'Light', 'apply', 'medium'),
            2 => self::label('multichoice', 'Light', 'apply', 'medium')];
        [$attempts, $answers] = self::graded_class($questions, 2);
        // Question 2: right for everybody but the four strongest, who all chose the same wrong option.
        foreach ($answers as &$answer) {
            if ($answer['slot'] === 2) {
                $answer['mark'] = $answer['user'] > 6 ? 0.0 : 1.0;
                $answer['response'] = $answer['user'] > 6 ? 'The temperature is too high' : 'Light';
            }
        }
        unset($answer);
        $a = analysis::compute(10, $attempts, $answers, $questions, 2.0, null);

        $this->assertSame([], $a['questions'][0]['notes'], 'A question that separates well, at 55%.');
        $this->assertGreaterThan(0.9, $a['questions'][0]['sep']);
        $keys = array_column($a['questions'][1]['notes'], 'key');
        $this->assertSame(['weak', 'chose'], $keys);
        $this->assertSame('The temperature is too high', $a['questions'][1]['notes'][1]['answer']);
        $this->assertEquals(40, $a['questions'][1]['notes'][1]['pct']);

        // Seven answers: no notes at all.
        $few = array_values(array_filter($answers, fn ($x) => $x['user'] <= 7));
        $a = analysis::compute(10, array_slice($attempts, 0, 7, true), $few, $questions, 2.0, null);
        $this->assertSame([], array_merge(...array_column($a['questions'], 'notes')));
    }

    /**
     * The correlation, and the topic a student should revise first.
     */
    public function test_helpers(): void {
        $this->assertEqualsWithDelta(1.0, analysis::correlation([1, 2, 3], [2, 4, 6]), 0.0001);
        $this->assertEqualsWithDelta(-1.0, analysis::correlation([1, 2, 3], [3, 2, 1]), 0.0001);
        $this->assertNull(analysis::correlation([1, 1, 1], [1, 2, 3]), 'No spread, no answer.');
        $this->assertNull(analysis::correlation([1, 2], [1, 2]));

        $this->assertSame('B', analysis::revise_first(['A' => [1, 1], 'B' => [0, 0.5], 'C' => [0], '' => [0, 0]]));
        $this->assertSame('C', analysis::revise_first(['A' => [1], 'C' => [0]]), 'No topic with two answers: any topic.');
        $this->assertNull(analysis::revise_first(['' => [0]]));
    }

    /**
     * A real quiz: each student's first finished attempt only, a teacher's preview never, labels from Quizbot's record.
     */
    public function test_real_quiz(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id, 'questionsperpage' => 0, 'grade' => 100,
            'sumgrades' => 2]);
        $questions = $generator->get_plugin_generator('core_question');
        $category = $questions->create_question_category(['contextid' => \context_module::instance($quiz->cmid)->id]);
        $frog = $questions->create_question('shortanswer', null, ['category' => $category->id]);
        $true = $questions->create_question('truefalse', null, ['category' => $category->id]);
        quiz_add_quiz_question($frog->id, $quiz);
        quiz_add_quiz_question($true->id, $quiz);
        if (class_exists(\mod_quiz\quiz_settings::class)) {
            \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        } else {
            quiz_update_sumgrades($quiz);
        }
        $DB->insert_record('local_quizbot_question', (object) [
            'questionbankentryid' => $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $frog->id]),
            'questionid' => $frog->id, 'courseid' => $course->id, 'cmid' => $quiz->cmid, 'userid' => 2,
            'qtype' => 'shortanswer', 'sourcename' => 'Frogs (PDF)', 'topic' => 'Frog names', 'bloom' => 'remember',
            'difficulty' => 'easy', 'timecreated' => time(),
        ]);

        $students = [];
        foreach (range(1, 3) as $i) {
            $students[$i] = $generator->create_and_enrol($course, 'student');
        }
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $take = function (\stdClass $user, array $responses) use ($quizgenerator, $quiz) {
            $this->setUser($user);
            $attempt = $quizgenerator->create_attempt($quiz->id, $user->id);
            $quizgenerator->submit_responses($attempt->id, $responses, false, true);
        };
        $take($students[1], [1 => 'frog', 2 => 'True']);
        $take($students[2], [1 => 'toad', 2 => 'False']);
        $take($students[2], [1 => 'frog', 2 => 'True']);
        $take($teacher, [1 => 'frog', 2 => 'True']);
        $this->setAdminUser();

        [$course, $cm] = get_course_and_cm_from_cmid($quiz->cmid, 'quiz');
        $quiz = $DB->get_record('quiz', ['id' => $quiz->id]);
        $a = analysis::compute(...analysis::gather($cm, $quiz, []));

        $this->assertSame(3, $a['classsize'], 'Three students; the teacher is not one.');
        $this->assertSame(2, $a['finished']);
        // Student 2's first attempt: "toad" is worth 80%, "False" nothing - the better second attempt does not count.
        $this->assertEqualsWithDelta(70.0, $a['average'], 0.01);
        $this->assertCount(1, $a['below']);
        $this->assertEquals($students[2]->id, $a['below'][0]['user']);
        $this->assertSame('Frogs (PDF)', $a['below'][0]['revise']);
        $this->assertSame(['', 'Frogs (PDF)'], array_column($a['topics'], 'name'));
        $this->assertSame(['shortanswer', 'truefalse'], array_column($a['questions'], 'qtype'));
        $this->assertSame('remember', $a['questions'][0]['bloom']);
        $this->assertEqualsWithDelta(90.0, $a['questions'][0]['pct'], 0.01);
    }
}
