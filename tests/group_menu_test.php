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

use local_quizbot\local\group_menu;

/**
 * Tests for the groups menu of the analysis pages: a line for each year above its classes.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\local\group_menu
 */
final class group_menu_test extends \advanced_testcase {
    /**
     * Years from class names, and from the course's groupings.
     */
    public function test_years(): void {
        $classes = [1 => '10A', 2 => '10B', 3 => '11A', 4 => '11B', 5 => 'Choir'];
        $years = group_menu::years($classes, [], true);
        $this->assertSame([
            ['key' => 'n1', 'name' => 'All Grade 10', 'groups' => [1, 2]],
            ['key' => 'n3', 'name' => 'All Grade 11', 'groups' => [3, 4]],
        ], $years);

        // A grouping names its year; the same classes found by name are not listed twice.
        $years = group_menu::years($classes, [7 => ['name' => 'Year 10', 'groups' => [2, 1]]], true);
        $this->assertSame(['g7', 'n3'], array_column($years, 'key'));
        $this->assertSame('All Year 10', $years[0]['name']);
        $this->assertSame([1, 2], $years[0]['groups'], 'The classes in menu order.');

        // A grouping with only one of the teacher's classes is no year.
        $this->assertSame(['n1', 'n3'], array_column(group_menu::years(
            $classes,
            [8 => ['name' => 'Y', 'groups' => [1, 9]]],
            true
        ), 'key'));

        // Every class is "All participants" already, unless the teacher may see only some classes.
        $this->assertSame([], group_menu::years([1 => '10A', 2 => '10B'], [], true));
        $this->assertCount(1, group_menu::years([1 => '10A', 2 => '10B'], [], false));

        // Other ways of naming classes: the year keeps the school's own word; a year of six classes is one short line.
        $this->assertSame('All Year 9', group_menu::years(
            [1 => 'Year 9 A', 2 => 'year 9-b', 3 => 'Red'],
            [],
            true
        )[0]['name']);
        $many = [1 => '7A', 2 => '7B', 3 => '7C', 4 => '7D', 5 => '7E', 6 => '7F', 7 => '8A'];
        $this->assertSame(
            ['name' => 'All Grade 7', 'groups' => [1, 2, 3, 4, 5, 6]],
            array_intersect_key(group_menu::years($many, [], true)[0], ['name' => 1, 'groups' => 1])
        );
        $this->assertSame([], group_menu::years([1 => 'Red', 2 => 'Blue', 3 => 'Year 2026'], [], true));
    }

    /**
     * The menu on a quiz with visible groups: the year lines above their classes, the chosen year remembered until a
     * class is chosen.
     */
    public function test_for_page(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $g = [];
        foreach (['10A', '10B', '11A'] as $name) {
            $g[$name] = $gen->create_group(['courseid' => $course->id, 'name' => $name])->id;
        }
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'groupmode' => VISIBLEGROUPS]);
        [$course, $cm] = get_course_and_cm_from_cmid($quiz->cmid, 'quiz');
        $url = new \moodle_url('/local/quizbot/analysis.php', ['cmid' => $cm->id]);
        $PAGE->set_url($url);
        $PAGE->set_context(\context_module::instance($cm->id));

        $menu = group_menu::for_page($course, $cm, $url);
        $this->assertSame([], $menu['groups'], 'Everybody at first.');
        $this->assertMatchesRegularExpression('/All participants.*All Grade 10.*>10A<.*>10B<.*>11A</s', $menu['html']);

        $_GET['year'] = 'n' . $g['10A'];
        $menu = group_menu::for_page($course, $cm, $url);
        $this->assertSame([(int) $g['10A'], (int) $g['10B']], $menu['groups']);
        $this->assertSame('n' . $g['10A'], $menu['year']);

        unset($_GET['year']);
        $this->assertSame('n' . $g['10A'], group_menu::for_page($course, $cm, $url)['year'], 'Remembered.');

        $_GET['group'] = $g['11A'];
        $menu = group_menu::for_page($course, $cm, $url);
        $this->assertSame([(int) $g['11A']], $menu['groups']);
        $this->assertSame('', $menu['year']);
        unset($_GET['group']);
    }

    /**
     * Separate groups: a teacher who may not see all groups gets only their own classes, and no results without one.
     */
    public function test_separate_groups(): void {
        global $PAGE;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $a = $gen->create_group(['courseid' => $course->id, 'name' => '10A']);
        $gen->create_group(['courseid' => $course->id, 'name' => '10B']);
        $quiz = $gen->create_module('quiz', ['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);
        [$course, $cm] = get_course_and_cm_from_cmid($quiz->cmid, 'quiz');
        $url = new \moodle_url('/local/quizbot/analysis.php', ['cmid' => $cm->id]);
        $PAGE->set_url($url);
        $PAGE->set_context(\context_module::instance($cm->id));

        $teacher = $gen->create_and_enrol($course, 'teacher');
        $gen->create_group_member(['groupid' => $a->id, 'userid' => $teacher->id]);
        $this->setUser($teacher);
        $menu = group_menu::for_page($course, $cm, $url);
        $this->assertSame([(int) $a->id], $menu['groups']);
        $this->assertStringNotContainsString('All Grade 10', $menu['html']);
        $this->assertStringNotContainsString('All participants', $menu['html']);

        $this->setUser($gen->create_and_enrol($course, 'teacher'));
        $this->assertTrue(group_menu::for_page($course, $cm, $url)['nogroup']);
    }
}
