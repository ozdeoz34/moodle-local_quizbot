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

use local_quizbot\local\course_analysis;

/**
 * Tests for the analysis of a whole course: the sums across its quizzes and the students who need attention.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\local\course_analysis
 * @covers     \local_quizbot\local\page
 */
final class course_analysis_test extends \advanced_testcase {
    /**
     * A topic as analysis::compute() gives it.
     *
     * @param string $name
     * @param float $sum marks
     * @param int $n answers
     * @return array
     */
    private static function topic(string $name, float $sum, int $n): array {
        return ['name' => $name, 'other' => false, 'pct' => $sum / $n * 100, 'sum' => $sum, 'n' => $n];
    }

    /**
     * Which way scores are going.
     */
    public function test_direction(): void {
        $this->assertSame('steady', course_analysis::direction([50, 52, 53]));
        $this->assertSame('rising', course_analysis::direction([40, 50, 60]));
        $this->assertSame('falling', course_analysis::direction([60, 50, 40]));
        $this->assertSame('upanddown', course_analysis::direction([40, 60, 50]));
    }

    /**
     * Topics and skills summed over the quizzes, weakest topic first; a quiz nobody has taken listed but not counted;
     * a student low over two quizzes needs attention, a student with one quiz does not.
     */
    public function test_compute(): void {
        $a = ['finished' => 2, 'average' => 55.0,
            'topics' => [self::topic('Light', 4, 10), self::topic('Calvin', 7, 10)],
            'skills' => [['level' => 'remember', 'sum' => 6, 'n' => 10], ['level' => 'apply', 'sum' => 5, 'n' => 10]],
            'scores' => [1 => 40.0, 2 => 70.0], 'finish' => [1 => 100, 2 => 100],
            'usertopics' => [1 => ['Light' => [0, 0, 1], 'Calvin' => [1]]]];
        $b = ['finished' => 2, 'average' => 60.0, 'topics' => [self::topic('Light', 6, 10)],
            'skills' => [['level' => 'remember', 'sum' => 4, 'n' => 10]],
            'scores' => [1 => 50.0, 3 => 90.0], 'finish' => [1 => 200, 3 => 200],
            'usertopics' => [1 => ['Light' => [1, 0]]]];
        $c = course_analysis::compute([
            ['cmid' => 1, 'name' => 'Quiz A', 'timeopen' => 0, 'analysis' => $a],
            ['cmid' => 2, 'name' => 'Quiz B', 'timeopen' => 0, 'analysis' => $b],
            ['cmid' => 3, 'name' => 'Quiz C', 'timeopen' => 0, 'analysis' => null],
        ], 3);

        $this->assertSame(3, $c['quizcount']);
        $this->assertSame(2, $c['taken']);
        $this->assertEqualsWithDelta(57.5, $c['average'], 0.01);
        $this->assertSame([55.0, 60.0], $c['trend']);
        $this->assertSame(['Light', 'Calvin'], array_column($c['topics'], 'name'));
        $this->assertEqualsWithDelta(50.0, $c['topics'][0]['pct'], 0.01, '10 marks of 20 answers over both quizzes.');
        $this->assertSame(['Quiz A', 'Quiz B'], $c['topics'][0]['quizzes']);
        $this->assertSame(['remember', 'apply'], array_column($c['skills'], 'level'));
        $this->assertEqualsWithDelta(50.0, $c['skills'][0]['pct'], 0.01);
        $this->assertSame('Light', $c['quizzes'][0]['weakest']['name']);
        $this->assertFalse($c['quizzes'][2]['taken']);

        $this->assertCount(1, $c['attention'], 'Student 2 and 3 did one quiz each.');
        $this->assertSame(1, $c['attention'][0]['user']);
        $this->assertEqualsWithDelta(45.0, $c['attention'][0]['average'], 0.01);
        $this->assertSame('rising', $c['attention'][0]['direction']);
        $this->assertSame('Light', $c['attention'][0]['revise']);
    }

    /**
     * The rows of the "Students who need attention" table (whole-course page; foot of a quiz's analysis for a year or a
     * class): in the order given, a falling series marked, no topic shown as a dash, a deleted account left out.
     */
    public function test_attention_rows(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $uma = $gen->create_user(['firstname' => 'Uma', 'lastname' => 'Okafor']);
        $mei = $gen->create_user(['firstname' => 'Mei', 'lastname' => 'Tanaka']);

        $this->assertSame([], course_analysis::attention_rows([], $course));

        $rows = course_analysis::attention_rows([
            ['user' => (int) $uma->id, 'done' => 3, 'average' => 17.0, 'series' => [13.0, 13.0, 25.0],
                'direction' => 'upanddown', 'revise' => 'Week 2 overview'],
            ['user' => (int) $mei->id, 'done' => 3, 'average' => 21.0, 'series' => [38.0, 25.0, 0.0],
                'direction' => 'falling', 'revise' => null],
            ['user' => 999999, 'done' => 2, 'average' => 30.0, 'series' => [30.0, 30.0], 'direction' => 'steady',
                'revise' => 'Light'],
        ], $course);

        $this->assertCount(2, $rows, 'The account that is gone is left out.');
        $this->assertSame('Uma Okafor', $rows[0]['name']);
        $this->assertSame(3, $rows[0]['done']);
        $this->assertSame('17%', $rows[0]['average']);
        $this->assertSame('Up and down: 13% → 13% → 25%', $rows[0]['direction']);
        $this->assertFalse($rows[0]['falling']);
        $this->assertSame('Week 2 overview', $rows[0]['revise']);
        $this->assertStringContainsString('/local/quizbot/myprogress.php?id=' . $course->id, $rows[0]['progressurl']);
        $this->assertStringContainsString('userid=' . $uma->id, $rows[0]['progressurl']);
        $this->assertSame('Falling: 38% → 25% → 0%', $rows[1]['direction']);
        $this->assertTrue($rows[1]['falling']);
        $this->assertSame('–', $rows[1]['revise']);
    }

    /**
     * With two or more classes shown together (a whole year), each row says which class the student is in; a student in
     * none of them gets a dash; a group that is not one of the classes shown (a remedial quiz's group) is not named.
     */
    public function test_attention_rows_classes(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $a = $gen->create_group(['courseid' => $course->id, 'name' => '10A']);
        $b = $gen->create_group(['courseid' => $course->id, 'name' => '10B']);
        $other = $gen->create_group(['courseid' => $course->id, 'name' => 'Remedial: Light']);
        $students = [];
        foreach (['uma' => [$a, $other], 'mei' => [$b], 'noa' => []] as $name => $groups) {
            $students[$name] = $gen->create_and_enrol($course, 'student', ['firstname' => ucfirst($name)]);
            foreach ($groups as $g) {
                $gen->create_group_member(['groupid' => $g->id, 'userid' => $students[$name]->id]);
            }
        }
        $attention = [];
        foreach ($students as $s) {
            $attention[] = ['user' => (int) $s->id, 'done' => 2, 'average' => 40.0, 'series' => [40.0, 40.0],
                'direction' => 'steady', 'revise' => 'Light'];
        }
        $classes = [(int) $a->id => '10A', (int) $b->id => '10B'];

        $rows = course_analysis::attention_rows($attention, $course, $classes);
        $this->assertSame(['10A', '10B', '–'], array_column($rows, 'class'));

        $rows = course_analysis::attention_rows($attention, $course, [(int) $a->id => '10A']);
        $this->assertSame(['–', '–', '–'], array_column($rows, 'class'), 'One class: no column, nothing looked up.');
    }

    /**
     * Figures in the order they happened, with an arrow that points the way the page is read (left to right here).
     */
    public function test_series(): void {
        $this->assertSame('→', \local_quizbot\local\page::arrow());
        $this->assertSame('48% → 68%', \local_quizbot\local\page::series(['48%', '68%']));
        $this->assertSame('48%', \local_quizbot\local\page::series(['48%']));
    }
}
