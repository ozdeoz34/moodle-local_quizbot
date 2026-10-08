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

/**
 * Tests for the class that turns Quizbot's questions into Moodle questions and adds them to a quiz.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\local\question_saver
 */
final class question_saver_test extends \advanced_testcase {
    /**
     * Loads the sample questions.
     */
    public static function setUpBeforeClass(): void {
        require_once(__DIR__ . '/fixtures/sample_questions.php');
        parent::setUpBeforeClass();
    }

    /**
     * One question of every kind, in the shape the Quizbot service returns.
     *
     * @return array
     */
    private static function questions(): array {
        return local_quizbot_sample_questions();
    }

    /**
     * LaTeX becomes readable plain text for a question's name.
     *
     * @dataProvider readable_provider
     * @param string $tex
     * @param string $expected
     */
    public function test_readable(string $tex, string $expected): void {
        $this->assertSame($expected, question_saver::readable($tex));
    }

    /**
     * Cases for test_readable.
     *
     * @return array
     */
    public static function readable_provider(): array {
        return [
            'no maths' => ['No maths here at all.', 'No maths here at all.'],
            'integral with limits' => ['What is \(\int_{0}^{1} x e^{x}\,dx\)?', 'What is ∫₀¹ x eˣ dx?'],
            'fraction and root' => ['Simplify \(\frac{1}{2}x^{2} \le \sqrt{x}\).', 'Simplify 1/2x² ≤ √x.'],
            'degrees' => ['Between \(0^\circ\) and \(90^\circ\).', 'Between 0° and 90°.'],
            'words in a formula' => ['Light travels at \(3 \times 10^{8}\text{ m/s}\).', 'Light travels at 3 × 10⁸ m/s.'],
        ];
    }

    /**
     * Every kind of question becomes one well-formed element of Moodle XML.
     */
    public function test_to_xml_holds_every_question(): void {
        $xml = simplexml_load_string(question_saver::to_xml(self::questions()));
        $this->assertNotFalse($xml);
        $types = [];
        foreach ($xml->question as $question) {
            $types[] = (string) $question['type'];
        }
        $this->assertSame(['multichoice', 'truefalse', 'shortanswer', 'numerical', 'matching', 'essay'], $types);
        // The name is readable without the maths filter; the question text keeps its LaTeX for the filter.
        $this->assertSame('What is ∫ x eˣ dx?', (string) $xml->question[0]->name->text);
        $this->assertStringContainsString('\(\int x e^{x}\,dx\)', (string) $xml->question[0]->questiontext->text);
    }

    /**
     * A question that cannot work in Moodle is left out, not saved broken.
     */
    public function test_incomplete_questions_are_left_out(): void {
        $questions = self::questions();
        $questions[0]['options'][1]['correct'] = true;          // Two correct options.
        $questions[1]['answer'] = 'perhaps';                     // Not true or false.
        $questions[4]['pairs'] = array_slice($questions[4]['pairs'], 0, 2);     // Too few pairs.
        $xml = simplexml_load_string(question_saver::to_xml($questions));
        $this->assertCount(3, $xml->question);
    }

    /**
     * Text from the service cannot act as markup.
     */
    public function test_text_is_escaped(): void {
        $question = ['type' => 'essay', 'source' => 's', 'text' => 'Is <script>alert(1)</script> safe & sound?', 'graderinfo' => '',
            'feedback' => ''];
        $xml = simplexml_load_string(question_saver::to_xml([$question]));
        $this->assertNotFalse($xml);
        $this->assertStringContainsString('&lt;script&gt;', (string) $xml->question[0]->questiontext->text);
        $this->assertStringNotContainsString('<script>', (string) $xml->question[0]->questiontext->text);
    }

    /**
     * The questions end up in the quiz, each worth one mark, with the right answers.
     *
     * @dataProvider keep_provider
     * @param string $keep where the questions are kept
     */
    public function test_save_adds_the_questions_to_the_quiz(string $keep): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        [$course, $cm] = get_course_and_cm_from_cmid($quiz->cmid, 'quiz');

        $added = question_saver::save(self::questions(), $course, $cm, $keep);

        $this->assertSame(6, $added);
        $this->assertEquals(6, $DB->count_records('quiz_slots', ['quizid' => $quiz->id]));
        $this->assertEquals(6, $DB->get_field('quiz', 'sumgrades', ['id' => $quiz->id]));
        $types = $DB->get_fieldset_sql("SELECT q.qtype
              FROM {quiz_slots} s
              JOIN {question_references} r ON r.component = 'mod_quiz' AND r.questionarea = 'slot' AND r.itemid = s.id
              JOIN {question_versions} v ON v.questionbankentryid = r.questionbankentryid
              JOIN {question} q ON q.id = v.questionid
             WHERE s.quizid = ?
          ORDER BY s.slot", [$quiz->id]);
        $this->assertSame(['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'essay'], $types);

        // The true/false statement was false: "False" is the answer that scores.
        $truefalse = $DB->get_record(
            'question',
            ['qtype' => 'truefalse', 'name' => 'Water boils at 50 degrees at sea level.'],
            '*',
            MUST_EXIST
        );
        $right = $DB->get_field_select('question_answers', 'answer', 'question = ? AND fraction > 0.5', [$truefalse->id]);
        $this->assertSame('false', strtolower($right));
        // The multiple-choice question keeps exactly one correct option of four.
        $multichoice = $DB->get_record('question', ['qtype' => 'multichoice'], '*', MUST_EXIST);
        $this->assertEquals(4, $DB->count_records('question_answers', ['question' => $multichoice->id]));
        $this->assertEquals(1, $DB->count_records_select(
            'question_answers',
            'question = ? AND fraction > 0.5',
            [$multichoice->id]
        ));
    }

    /**
     * The two places questions can be kept.
     *
     * @return array
     */
    public static function keep_provider(): array {
        return ['the quiz itself' => ['quiz'], 'the course question bank' => ['shared']];
    }

    /**
     * Each added question is recorded with its topic, Bloom's level and difficulty, against the Moodle question it
     * became - also when an incomplete question in between is left out.
     */
    public function test_save_keeps_the_labels(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        [$course, $cm] = get_course_and_cm_from_cmid($quiz->cmid, 'quiz');
        $questions = self::questions();
        $questions[0] += ['topic' => 'Integration by parts', 'bloom' => 'apply', 'difficulty' => 'hard'];
        $questions[1] += ['topic' => 'Boiling point', 'bloom' => 'remember', 'difficulty' => 'easy', 'edited' => true];
        $questions[2] += ['bloom' => 'guess', 'difficulty' => 'very', 'rewritten' => true];
        array_splice($questions, 1, 0, [['type' => 'truefalse', 'source' => 's1', 'text' => 'Not finished.', 'answer' => 'maybe',
            'bloom' => 'understand']]);

        $this->assertSame(6, question_saver::save($questions, $course, $cm, 'quiz', 77, ['s1' => 'Maths notes.pdf']));

        $rows = array_values($DB->get_records('local_quizbot_question', null, 'id'));
        $this->assertCount(6, $rows);
        $this->assertSame(['apply', 'remember', '', '', '', ''], array_map(fn ($r) => (string) $r->bloom, $rows));
        $this->assertSame(['Integration by parts', 'Boiling point'], array_slice(array_map(fn ($r) => $r->topic, $rows), 0, 2));
        $this->assertSame(['hard', 'easy', ''], array_slice(array_map(fn ($r) => (string) $r->difficulty, $rows), 0, 3));
        $this->assertEquals([0, 1, 0, 0, 0, 0], array_column($rows, 'edited'));
        $this->assertEquals([0, 0, 1, 0, 0, 0], array_column($rows, 'rewritten'));
        foreach ($rows as $row) {
            // The record points at the question it describes.
            $qtype = $DB->get_field_sql('SELECT q.qtype
                  FROM {question_versions} v
                  JOIN {question} q ON q.id = v.questionid
                 WHERE v.questionbankentryid = ? AND q.id = ?', [$row->questionbankentryid, $row->questionid]);
            $this->assertSame($row->qtype, $qtype);
            $this->assertEquals([$course->id, $cm->id, 77, $USER->id, 'Maths notes.pdf'], [$row->courseid, $row->cmid,
                $row->jobid, $row->userid, $row->sourcename]);
        }
    }

    /**
     * Nothing to save is not an error.
     */
    public function test_save_nothing(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        [$course, $cm] = get_course_and_cm_from_cmid($quiz->cmid, 'quiz');
        $this->assertSame(0, question_saver::save([], $course, $cm, 'quiz'));
    }
}
