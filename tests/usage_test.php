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

use local_quizbot\local\usage;

/**
 * Tests for the site administrator's usage page: teachers, courses and questions added week by week.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\local\usage
 */
final class usage_test extends \advanced_testcase {
    /**
     * Questions added in the licence year are counted by teacher, course and week; a teacher who only tried the wizard
     * is listed; questions from before the year are not counted.
     */
    public function test_gather(): void {
        global $DB;
        $this->resetAfterTest();

        $now = strtotime('2026-10-08 12:00:00 UTC');
        $since = $now - 365 * DAYSECS;
        $entry = 0;
        $add = function (int $userid, int $courseid, int $cmid, int $time, int $n) use ($DB, &$entry): void {
            for ($i = 0; $i < $n; $i++) {
                $entry++;
                $DB->insert_record('local_quizbot_question', ['questionbankentryid' => $entry, 'questionid' => $entry,
                    'courseid' => $courseid, 'cmid' => $cmid, 'userid' => $userid, 'qtype' => 'truefalse',
                    'timecreated' => $time]);
            }
        };
        $gen = $this->getDataGenerator();
        $a = $gen->create_course()->id;
        $b = $gen->create_course()->id;
        $qa = (int) $gen->create_module('quiz', ['course' => $a])->cmid;
        $qb = (int) $gen->create_module('quiz', ['course' => $a])->cmid;
        $qc = (int) $gen->create_module('quiz', ['course' => $b])->cmid;
        $add(11, $a, $qa, $now - DAYSECS, 3);
        $add(11, $b, $qc, $now - 40 * DAYSECS, 1);
        $add(12, $a, $qb, $now - 100 * DAYSECS, 2);
        $add(12, $a, $qc + 1000, $now - 100 * DAYSECS, 1);
        $add(12, $a, $qb, $since - DAYSECS, 5);
        foreach ([11, 13] as $userid) {
            $DB->insert_record('local_quizbot_job', ['userid' => $userid, 'courseid' => $a, 'cmid' => $qa,
                'timecreated' => $now - 2 * DAYSECS, 'timemodified' => $now - 2 * DAYSECS]);
        }

        $u = usage::gather($since, $now);
        $this->assertSame(7, $u['questions']);
        $this->assertSame([11, 12, 13], array_keys($u['teachers']), 'Most questions first.');
        $this->assertSame(4, $u['teachers'][11]['questions']);
        $this->assertCount(2, $u['teachers'][11]['courses']);
        $this->assertSame($now - DAYSECS, $u['teachers'][11]['last']);
        $this->assertTrue($u['teachers'][13]['triedonly']);
        $this->assertSame(2, $u['active'], 'Teachers 11 and 13 in the last 30 days.');
        $this->assertSame(6, $u['courses'][$a]['questions'], 'The question added to a quiz deleted since still counts.');
        $this->assertEqualsCanonicalizing([$qa, $qb], array_keys($u['courses'][$a]['quizzes']), 'The deleted quiz does not.');
        $this->assertSame(3, $u['quizzes']);
        $this->assertCount(usage::WEEKS, $u['weeks']);
        $this->assertSame(3, $u['weeks'][usage::WEEKS - 1]['n'], 'This week.');
        $this->assertSame(4, array_sum(array_column($u['weeks'], 'n')), 'The question 100 days ago is before the chart.');
        $this->assertSame(0, $u['students']);
        $this->assertNull($u['average']);
    }
}
