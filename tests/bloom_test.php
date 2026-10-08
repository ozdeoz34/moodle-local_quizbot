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

use local_quizbot\local\bloom;
use local_quizbot\local\job;

/**
 * Tests for Bloom's levels: which kinds can test which level, the plan sent to Quizbot, and the check of a teacher's
 * numbers.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\local\bloom
 */
final class bloom_test extends \advanced_testcase {
    /**
     * The table is the Quizbot service's (QuestionWriter::BLOOM_FITS), seen from the other side: the service refuses
     * a plan that puts a level on a kind that cannot test it.
     */
    public function test_fits_match_the_service(): void {
        $service = [
            'multichoice' => ['remember', 'understand', 'apply', 'analyse', 'evaluate'],
            'truefalse' => ['remember', 'understand'],
            'shortanswer' => ['remember', 'understand', 'apply'],
            'numerical' => ['remember', 'apply', 'analyse'],
            'match' => ['remember', 'understand', 'analyse'],
            'essay' => ['understand', 'apply', 'analyse', 'evaluate', 'create'],
        ];
        $ours = array_fill_keys(job::TYPES, []);
        foreach (bloom::LEVELS as $level) {
            foreach (bloom::FITS[$level] as $type) {
                $ours[$type][] = $level;
            }
        }
        foreach ($service as $type => $levels) {
            $this->assertEqualsCanonicalizing($levels, $ours[$type], $type);
        }
        $this->assertEqualsCanonicalizing(bloom::LEVELS, array_keys(bloom::FITS));
    }

    /**
     * Each level goes to its most natural kind while there is room.
     */
    public function test_plan_natural_kinds(): void {
        $result = bloom::plan(
            ['multichoice' => 4, 'truefalse' => 2, 'essay' => 2],
            ['create' => 2, 'evaluate' => 2, 'understand' => 2, 'remember' => 2]
        );
        $this->assertSame(0, $result['unmet']);
        $this->assertEquals([
            'multichoice' => ['understand' => 2, 'evaluate' => 2],
            'truefalse' => ['remember' => 2],
            'essay' => ['create' => 2],
        ], $result['plan']);
        $this->assertSame(0, array_sum($result['left']));
    }

    /**
     * An earlier choice is moved to another kind when a later level has nowhere else to go.
     */
    public function test_plan_moves_an_earlier_choice(): void {
        // The level "apply" first takes the short answer; "remember" then needs it, so "apply" moves on to the essay.
        $result = bloom::plan(['shortanswer' => 1, 'essay' => 1], ['apply' => 1, 'remember' => 1]);
        $this->assertSame(0, $result['unmet']);
        $this->assertEquals(['shortanswer' => ['remember' => 1], 'essay' => ['apply' => 1]], $result['plan']);
    }

    /**
     * Questions with no level asked for are left free; levels with no room are counted.
     */
    public function test_plan_left_and_unmet(): void {
        $result = bloom::plan(['multichoice' => 3, 'essay' => 1], ['create' => 2]);
        $this->assertSame(1, $result['unmet']);
        $this->assertEquals(['essay' => ['create' => 1]], $result['plan']);
        $this->assertSame(3, $result['left']['multichoice']);
    }

    /**
     * The check names the levels that ask for more than their kinds have.
     */
    public function test_misfit(): void {
        $this->assertNull(bloom::misfit(['multichoice' => 5], ['remember' => 2, 'evaluate' => 3]));

        $worst = bloom::misfit(['truefalse' => 5], ['analyse' => 2]);
        $this->assertSame(['analyse'], $worst['levels']);
        $this->assertSame(2, $worst['wanted']);
        $this->assertSame(0, $worst['have']);

        // Create can only be an essay: one essay for two "create" questions.
        $worst = bloom::misfit(['essay' => 1, 'multichoice' => 9], ['create' => 2]);
        $this->assertSame(['create'], $worst['levels']);
        $this->assertSame(['essay'], $worst['kinds']);
        $this->assertSame(1, $worst['have']);

        $this->assertSame('', bloom::misfit_text(['multichoice' => 2], ['remember' => 1]));
        $this->assertStringContainsString(
            get_string('bloom_create', 'local_quizbot'),
            bloom::misfit_text(['essay' => 1], ['create' => 2])
        );
    }

    /**
     * The check and the plan always agree: the numbers fit exactly when every asked-for question can be placed.
     */
    public function test_misfit_and_plan_agree(): void {
        mt_srand(1101);
        for ($case = 0; $case < 400; $case++) {
            $counts = [];
            foreach (job::TYPES as $type) {
                $counts[$type] = mt_rand(0, 3) ? 0 : mt_rand(1, 4);
            }
            $skills = [];
            foreach (bloom::LEVELS as $level) {
                $skills[$level] = mt_rand(0, 2) ? 0 : mt_rand(1, 4);
            }
            $unmet = bloom::plan($counts, $skills)['unmet'];
            $this->assertSame($unmet === 0, bloom::misfit($counts, $skills) === null, json_encode([$counts, $skills]));
            // A plan never puts a level on a kind that cannot test it, nor more questions on a kind than asked for.
            foreach (bloom::plan($counts, $skills)['plan'] as $type => $levels) {
                $this->assertLessThanOrEqual($counts[$type], array_sum($levels));
                foreach (array_keys($levels) as $level) {
                    $this->assertContains($type, bloom::FITS[$level]);
                }
            }
        }
    }

    /**
     * What a teacher typed becomes whole numbers within range, for every level and no others.
     */
    public function test_clean(): void {
        $clean = bloom::clean(['remember' => '3', 'apply' => -2, 'create' => 999, 'guess' => 5]);
        $this->assertSame(bloom::LEVELS, array_keys($clean));
        $this->assertSame(3, $clean['remember']);
        $this->assertSame(0, $clean['apply']);
        $this->assertSame(job::MAX_QUESTIONS, $clean['create']);
        $this->assertSame(0, $clean['understand']);
    }

    /**
     * A level's name, and none for anything else.
     */
    public function test_name(): void {
        $this->assertSame(get_string('bloom_analyse', 'local_quizbot'), bloom::name('analyse'));
        $this->assertSame('', bloom::name(''));
        $this->assertSame('', bloom::name('guess'));
    }
}
