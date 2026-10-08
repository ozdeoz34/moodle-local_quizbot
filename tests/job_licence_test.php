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

use local_quizbot\local\api;
use local_quizbot\local\job;
use local_quizbot\local\licence;
use local_quizbot\local\link_reader;

/**
 * Tests for a teacher's wizard run and for the licence's state. The Quizbot service is never called: the licence
 * tests use the states that need no answer from it.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\local\job
 * @covers     \local_quizbot\local\licence
 * @covers     \local_quizbot\local\api::teacher_code
 */
final class job_licence_test extends \advanced_testcase {
    /**
     * A teacher has one unfinished run per quiz; it keeps what was chosen.
     */
    public function test_current_run_is_kept(): void {
        global $DB;

        $this->resetAfterTest();
        $first = job::current(11, 2, 3);
        $this->assertSame(job::STATUS_DRAFT, $first->status);
        $this->assertSame([], $first->sourcesdata['keys']);
        $this->assertSame(link_reader::NONE, $first->sourcesdata['link']);
        $this->assertSame(10, job::total($first->optionsdata), 'The numbers the questions step starts with.');

        $first->sourcesdata['keys'] = ['f12', 't7'];
        $first->sourcesdata['text'] = 'Pasted notes.';
        $first->optionsdata['counts']['essay'] = 2;
        job::save($first);

        $again = job::current(11, 2, 3);
        $this->assertEquals($first->id, $again->id);
        $this->assertSame(['f12', 't7'], $again->sourcesdata['keys']);
        $this->assertSame('Pasted notes.', $again->sourcesdata['text']);
        $this->assertSame(12, job::total($again->optionsdata));

        // Another teacher, or another quiz, has a run of its own.
        $this->assertNotEquals($first->id, job::current(11, 2, 4)->id);
        $this->assertNotEquals($first->id, job::current(12, 2, 3)->id);

        // A finished run is not picked up again: the next visit starts a fresh one.
        $again->status = job::STATUS_SAVED;
        job::save($again);
        $this->assertNotEquals($first->id, job::current(11, 2, 3)->id);
        $this->assertEquals(4, $DB->count_records('local_quizbot_job'));
    }

    /**
     * The limits agreed for one run.
     */
    public function test_limits(): void {
        $this->assertSame(40, job::MAX_QUESTIONS);
        $this->assertSame(4, job::MAX_SOURCES);
        $this->assertSame(['numerical' => 10, 'match' => 5, 'essay' => 5], job::MAX_PER_TYPE);
        $this->assertSame(
            ['questions' => [], 'notes' => [], 'names' => [], 'unticked' => []],
            job::result((object) ['result' => null])
        );
    }

    /**
     * Without a key nothing is asked of the service, and the wizard is blocked.
     */
    public function test_licence_without_a_key(): void {
        $this->resetAfterTest();
        set_config('licencekey', '', 'local_quizbot');
        $status = licence::status();
        $this->assertFalse($status['ok']);
        $this->assertSame('licence_missing', $status['code']);
        $this->assertTrue(licence::blocks($status));
        $this->assertSame('', licence::left_text($status));
    }

    /**
     * Which answers stop the wizard, and which only warn.
     *
     * @dataProvider blocks_provider
     * @param string $code
     * @param bool $blocks
     */
    public function test_which_states_block(string $code, bool $blocks): void {
        $this->assertSame($blocks, licence::blocks(['ok' => false, 'code' => $code, 'message' => '', 'summary' => []]));
    }

    /**
     * Cases for test_which_states_block.
     *
     * @return array
     */
    public static function blocks_provider(): array {
        return [
            ['licence_invalid', true], ['licence_inactive', true], ['licence_expired', true], ['site_mismatch', true],
            ['limit_reached', true], ['teacher_limit', true], ['unreachable', false], ['busy', false],
        ];
    }

    /**
     * A working licence is said in numbers and a date.
     */
    public function test_left_text(): void {
        $this->resetAfterTest();
        $status = ['ok' => true, 'code' => '', 'message' => '', 'summary' => ['remaining' => 4320, 'limit' => 5000,
            'period_end' => '2027-09-30']];
        $this->assertFalse(licence::blocks($status));
        $text = licence::left_text($status);
        $this->assertStringContainsString('4320', $text);
        $this->assertStringContainsString('5000', $text);
        $this->assertStringContainsString('2027', $text);
    }

    /**
     * A school plan counts no questions for the school: no numbers are shown, only "unlimited" and the date.
     */
    public function test_left_text_of_a_school_plan(): void {
        $this->resetAfterTest();
        $status = ['ok' => true, 'code' => '', 'message' => '', 'summary' => ['remaining' => 119500, 'limit' => 120000,
            'unlimited' => true, 'period_end' => '2027-09-30']];
        $text = licence::left_text($status);
        $this->assertStringContainsString('unlimited', $text);
        $this->assertStringContainsString('2027', $text);
        $this->assertStringNotContainsString('120', $text);
        $this->assertStringNotContainsString('119', $text);
    }

    /**
     * "All the questions are used" is said in three ways, and only a teacher package offers to buy another one.
     */
    public function test_used_up_is_said_by_kind_of_licence(): void {
        $this->resetAfterTest();
        $state = fn (string $code, array $summary) => ['ok' => false, 'code' => $code, 'message' => '',
            'summary' => $summary + ['period_end' => '2027-09-30']];

        $package = $state('limit_reached', ['topup' => true, 'unlimited' => false]);
        $this->assertSame(get_string('blocked_limit_reached_topup_text', 'local_quizbot'), licence::blocked_text($package));
        $this->assertTrue(licence::can_buy_more($package));

        $school = $state('limit_reached', ['topup' => false, 'unlimited' => true]);
        $this->assertSame(get_string('blocked_limit_reached_fair_text', 'local_quizbot'), licence::blocked_text($school));
        $this->assertFalse(licence::can_buy_more($school));

        $other = $state('limit_reached', []);
        $this->assertStringContainsString('2027', licence::blocked_text($other), 'It says when the count starts again.');
        $this->assertFalse(licence::can_buy_more($other));

        // No free teacher place: on a teacher package another package adds a place; on a school plan it does not.
        $this->assertTrue(licence::can_buy_more($state('teacher_limit', ['topup' => true])));
        $this->assertFalse(licence::can_buy_more($state('teacher_limit', ['topup' => false, 'unlimited' => true])));
        $noplace = get_string('blocked_teacher_limit_text', 'local_quizbot');
        $this->assertSame($noplace, licence::blocked_text($state('teacher_limit', [])));
        $this->assertFalse(licence::can_buy_more(['ok' => true, 'code' => '', 'message' => '', 'summary' => ['topup' => true]]));
    }

    /**
     * The code that stands for a teacher: the same for the same person, another for another person, and nothing in
     * it that says who it is.
     */
    public function test_teacher_code(): void {
        $this->resetAfterTest();
        $one = $this->getDataGenerator()->create_user(['username' => 'margaret', 'email' => 'margaret@example.com']);
        $two = $this->getDataGenerator()->create_user();
        $code = api::teacher_code((int) $one->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $code);
        $this->assertSame($code, api::teacher_code((int) $one->id));
        $this->assertNotSame($code, api::teacher_code((int) $two->id));
        $this->assertStringNotContainsString('margaret', $code);
        $this->assertNotSame(hash('sha256', (string) $one->id), $code, 'Not guessable from the user number alone.');
    }
}
