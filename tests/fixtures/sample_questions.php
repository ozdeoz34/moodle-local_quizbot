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
 * Sample questions for the tests of local_quizbot.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * One question of every kind, in the shape the Quizbot service returns.
 *
 * @return array
 */
function local_quizbot_sample_questions(): array {
    return [
        ['type' => 'multichoice', 'source' => 's1', 'text' => 'What is \(\int x e^{x}\,dx\)?', 'feedback' => '', 'options' => [
            ['text' => '\(x e^{x} - e^{x} + C\)', 'correct' => true, 'feedback' => 'Integration by parts.'],
            ['text' => '\(x e^{x} + e^{x} + C\)', 'correct' => false, 'feedback' => 'The sign is wrong.'],
            ['text' => '\(e^{x} + C\)', 'correct' => false, 'feedback' => ''],
            ['text' => '\(x^{2} e^{x} + C\)', 'correct' => false, 'feedback' => ''],
        ]],
        ['type' => 'truefalse', 'source' => 's1', 'text' => 'Water boils at 50 degrees at sea level.', 'answer' => false,
            'feedback' => 'At 100.'],
        ['type' => 'shortanswer', 'source' => 's1', 'text' => 'Which gas do plants take in?', 'answers' => ['carbon dioxide',
            'CO2'], 'feedback' => ''],
        ['type' => 'numerical', 'source' => 's1', 'text' => 'How many sides has a hexagon?', 'answer' => 6, 'tolerance' => 0,
            'feedback' => ''],
        ['type' => 'match', 'source' => 's1', 'text' => 'Match each animal with its young.', 'feedback' => '', 'pairs' => [
            ['left' => 'Cat', 'right' => 'kitten'], ['left' => 'Dog', 'right' => 'puppy'], ['left' => 'Cow', 'right' => 'calf'],
            ['left' => 'Sheep', 'right' => 'lamb'],
        ]],
        ['type' => 'essay', 'source' => 's1', 'text' => 'Explain the water cycle.',
            'graderinfo' => 'Evaporation, condensation, rain.',
            'feedback' => ''],
    ];
}
