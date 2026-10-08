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

use local_quizbot\local\question_editor;

/**
 * Tests for editing a question on the review page.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\local\question_editor
 */
final class question_editor_test extends \advanced_testcase {
    /**
     * Loads the sample questions.
     */
    public static function setUpBeforeClass(): void {
        require_once(__DIR__ . '/fixtures/sample_questions.php');
        parent::setUpBeforeClass();
    }

    /**
     * What the edit form sent.
     *
     * @param array $fields
     */
    private function post(array $fields): void {
        $_POST = $fields;
    }

    /**
     * The form's values are not kept for the next test.
     */
    protected function tearDown(): void {
        $_POST = [];
        parent::tearDown();
    }

    /**
     * A multiple-choice question takes its new text, options and correct option.
     */
    public function test_multichoice_is_edited(): void {
        $original = local_quizbot_sample_questions()[0];
        $this->post(['text' => 'Which is a fruit?', 'opt' => ['Carrot', 'Potato', 'Apple', 'Leek'], 'optfb' => ['', '',
            'It grows on a tree.', ''],
            'correct' => '2']);
        [$edited, $errors] = question_editor::from_form($original);
        $this->assertSame([], $errors);
        $this->assertSame('Which is a fruit?', $edited['text']);
        $this->assertSame(['Carrot', 'Potato', 'Apple', 'Leek'], array_column($edited['options'], 'text'));
        $this->assertSame([false, false, true, false], array_column($edited['options'], 'correct'));
        $this->assertSame('It grows on a tree.', $edited['options'][2]['feedback']);
        $this->assertSame('multichoice', $edited['type'], 'The kind of question cannot be changed by editing.');
    }

    /**
     * What cannot be saved is said, field by field.
     *
     * @dataProvider refused_provider
     * @param int $question which of the sample questions
     * @param array $fields what the form sent
     * @param string $field the field that is wrong
     */
    public function test_a_broken_edit_is_refused(int $question, array $fields, string $field): void {
        $this->post($fields);
        [, $errors] = question_editor::from_form(local_quizbot_sample_questions()[$question]);
        $this->assertArrayHasKey($field, $errors);
    }

    /**
     * Cases for test_a_broken_edit_is_refused.
     *
     * @return array
     */
    public static function refused_provider(): array {
        $options = ['opt' => ['A', 'B', 'C', 'D'], 'correct' => '0'];
        return [
            'empty question' => [0, ['text' => '   '] + $options, 'text'],
            'an option missing' => [0, ['text' => 'Q', 'opt' => ['A', 'B', '', 'D'], 'correct' => '0'], 'options'],
            'two options alike' => [0, ['text' => 'Q', 'opt' => ['Alpha', 'alpha', 'C', 'D'], 'correct' => '0'], 'options'],
            'no correct option' => [0, ['text' => 'Q', 'opt' => ['A', 'B', 'C', 'D']], 'options'],
            'true/false not chosen' => [1, ['text' => 'Q'], 'answer'],
            'no accepted answer' => [2, ['text' => 'Q', 'ans' => ['', ' ']], 'answers'],
            'answer is not a number' => [3, ['text' => 'Q', 'answer' => 'six', 'tolerance' => '0'], 'answer'],
            'negative margin' => [3, ['text' => 'Q', 'answer' => '6', 'tolerance' => '-1'], 'tolerance'],
            'too few pairs' => [4, ['text' => 'Q', 'left' => ['a', 'b'], 'right' => ['1', '2']], 'pairs'],
            'half a pair' => [4, ['text' => 'Q', 'left' => ['a', 'b', 'c', 'd'], 'right' => ['1', '2', '3', '']], 'pairs'],
        ];
    }

    /**
     * The other kinds take their values too.
     */
    public function test_other_kinds_are_edited(): void {
        $samples = local_quizbot_sample_questions();

        $this->post(['text' => 'The sun is a star.', 'answer' => 'true', 'feedback' => 'Yes.']);
        [$truefalse, $errors] = question_editor::from_form($samples[1]);
        $this->assertSame([], $errors);
        $this->assertTrue($truefalse['answer']);
        $this->assertSame('Yes.', $truefalse['feedback']);

        $this->post(['text' => 'Q', 'ans' => ['groundwater', '', 'ground water', 'groundwater']]);
        [$short, $errors] = question_editor::from_form($samples[2]);
        $this->assertSame([], $errors);
        $this->assertSame(['groundwater', 'ground water'], $short['answers']);

        $this->post(['text' => 'Q', 'answer' => '12.5', 'tolerance' => '0.5']);
        [$number, $errors] = question_editor::from_form($samples[3]);
        $this->assertSame([], $errors);
        $this->assertEqualsWithDelta(12.5, $number['answer'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $number['tolerance'], 0.0001);

        $this->post(['text' => 'Q', 'left' => ['a', 'b', 'c', ''], 'right' => ['1', '2', '3', '']]);
        [$match, $errors] = question_editor::from_form($samples[4]);
        $this->assertSame([], $errors);
        $this->assertCount(3, $match['pairs']);

        $this->post(['text' => 'Q', 'graderinfo' => 'Three points.']);
        [$essay, $errors] = question_editor::from_form($samples[5]);
        $this->assertSame([], $errors);
        $this->assertSame('Three points.', $essay['graderinfo']);
    }

    /**
     * The edit page gets what it needs for every kind, and an empty row to add a pair.
     */
    public function test_for_template(): void {
        $samples = local_quizbot_sample_questions();
        $multichoice = question_editor::for_template($samples[0], ['text' => 'Wrong.']);
        $this->assertTrue($multichoice['ismultichoice']);
        $this->assertCount(4, $multichoice['options']);
        $this->assertTrue($multichoice['haserrors']);
        $this->assertSame('Wrong.', $multichoice['err_text']);

        $match = question_editor::for_template($samples[4], []);
        $this->assertCount(5, $match['pairs'], 'Four pairs and one empty row.');
        $this->assertFalse($match['haserrors']);
    }

    /**
     * The Bloom's level: one the kind can test is taken, one it cannot is refused quietly (the old one stays), and the
     * level can be taken away.
     */
    public function test_bloom_level(): void {
        $truefalse = local_quizbot_sample_questions()[1] + ['bloom' => 'remember'];
        $fields = ['text' => 'The sun is a star.', 'answer' => 'true'];

        $this->post($fields + ['bloom' => 'understand']);
        $edited = question_editor::from_form($truefalse)[0];
        $this->assertSame('understand', $edited['bloom']);
        $this->assertSame('remember', $edited['bloomwritten'], 'The level Quizbot wrote it at is kept.');
        $this->post($fields + ['bloom' => 'remember']);
        $this->assertSame('remember', question_editor::from_form($edited)[0]['bloomwritten'], 'Also after a second edit.');

        $this->post($fields + ['bloom' => 'create']);
        $this->assertSame('remember', question_editor::from_form($truefalse)[0]['bloom'], 'A true/false cannot test "create".');

        $this->post($fields + ['bloom' => '']);
        $this->assertSame('', question_editor::from_form($truefalse)[0]['bloom']);

        // A level Quizbot gave is kept although the kind does not usually test it.
        $this->post($fields + ['bloom' => 'apply']);
        $this->assertSame('apply', question_editor::from_form(['bloom' => 'apply'] + $truefalse)[0]['bloom']);

        // The choice offers the levels a true/false can test, and the question's own.
        $offered = question_editor::for_template($truefalse, [])['skills'];
        $this->assertSame(['remember', 'understand'], array_column($offered, 'value'));
        $skills = question_editor::for_template(['bloom' => 'apply'] + $truefalse, [])['skills'];
        $this->assertSame(['remember', 'understand', 'apply'], array_column($skills, 'value'));
        $this->assertSame([false, false, true], array_column($skills, 'selected'));
    }
}
