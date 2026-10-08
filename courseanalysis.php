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
 * Quizbot Analysis of a whole course, for its teachers (1.1): every quiz with Quizbot questions, topics and skills
 * across them, and the students who need attention. Reached from the course's Reports.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_quizbot\local\bloom;
use local_quizbot\local\course_analysis;
use local_quizbot\local\group_menu;
use local_quizbot\local\page;

require('../../config.php');

$courseid = required_param('id', PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/quizbot:viewanalysis', $context);

$url = new moodle_url('/local/quizbot/courseanalysis.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('courseanalysis', 'local_quizbot') . ': ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));

// The course's classes, and a line for each year above its classes.
$menu = group_menu::for_page($course, null, $url);
if ($menu['nogroup']) {
    echo $OUTPUT->header();
    echo page::desk_start(get_string('courseanalysis', 'local_quizbot'));
    echo $OUTPUT->notification(get_string('course_nogroup', 'local_quizbot'), \core\output\notification::NOTIFY_INFO);
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}
$c = course_analysis::gather($course, $menu['groups']);

$str = fn (string $key, $param = null): string => get_string($key, 'local_quizbot', $param);
$percent = fn (?float $pct): string => $pct === null ? '–' : format_float($pct, 0) . '%';
$bar = fn (?float $pct): array => ['width' => $pct === null ? 0 : max(2, (int) round($pct)),
    'weak' => $pct !== null && $pct < course_analysis::ATTENTION];

$tiles = [
    ['label' => $str('course_quizzes'), 'value' => $c['quizcount'], 'note' => $str('course_taken', $c['taken'])],
    ['label' => $str('course_students'), 'value' => $c['classsize'], 'note' => ''],
    ['label' => $str('course_average'), 'value' => $percent($c['average']),
        'note' => count($c['trend']) > 1 ? implode(' → ', array_map($percent, $c['trend'])) : ''],
    ['label' => $str('course_attention'), 'value' => count($c['attention']), 'warn' => (bool) $c['attention'],
        'note' => $str('course_attention_rule', ['pct' => course_analysis::ATTENTION, 'n' => course_analysis::ATTENTION_QUIZZES])],
];

// A quiz's results open for the same classes (when the quiz uses groups).
$same = $menu['year'] !== '' ? ['year' => $menu['year']] : ['group' => $menu['groups'][0] ?? 0];
$quizzes = [];
foreach ($c['quizzes'] as $q) {
    if ($q['taken']) {
        $state = $str('analysis_of', $c['classsize']);
    } else if ($q['timeopen'] > time()) {
        $state = $str('course_opens', userdate($q['timeopen'], get_string('strftimedateshort', 'langconfig')));
    } else {
        $state = $str('course_nottaken');
    }
    $quizzes[] = ['name' => $q['name'], 'taken' => $q['taken'], 'finished' => $q['finished'], 'state' => $state,
        'average' => $percent($q['average']),
        'weakest' => $q['weakest'] ? $q['weakest']['name'] . ' (' . $percent($q['weakest']['pct']) . ')' : '–',
        'url' => (new moodle_url('/local/quizbot/analysis.php', ['cmid' => $q['cmid']] + $same))->out(false)];
}

$topics = array_map(fn ($t) => ['name' => $t['name'], 'quizzes' => implode(', ', $t['quizzes']), 'pct' => $percent($t['pct']),
    'bar' => $bar($t['pct'])], $c['topics']);
$skills = array_map(
    fn ($s) => ['name' => bloom::name($s['level']), 'pct' => $percent($s['pct']), 'bar' => $bar($s['pct'])],
    $c['skills']
);

$students = [];
if ($c['attention']) {
    $users = $DB->get_records_list('user', 'id', array_column($c['attention'], 'user'), '', 'id, '
        . implode(', ', \core_user\fields::get_name_fields()));
    foreach ($c['attention'] as $s) {
        if (!isset($users[$s['user']])) {
            continue;
        }
        $students[] = ['name' => fullname($users[$s['user']]), 'done' => $s['done'], 'average' => $percent($s['average']),
            'direction' => $str('course_dir_' . $s['direction'], implode(' → ', array_map($percent, $s['series']))),
            'falling' => $s['direction'] === 'falling', 'revise' => $s['revise'] ?? '–',
            'progressurl' => (new moodle_url('/local/quizbot/myprogress.php', ['id' => $course->id, 'userid' => $s['user']]))
                ->out(false)];
    }
}

echo $OUTPUT->header();
echo page::desk_start($str('courseanalysis'));
echo $OUTPUT->render_from_template('local_quizbot/courseanalysis', [
    'coursename' => format_string($course->fullname),
    'groupmenu' => $menu['html'],
    'hasquizzes' => (bool) $quizzes,
    'tiles' => $tiles,
    'quizzes' => $quizzes,
    'topics' => $topics,
    'hastopics' => (bool) $topics,
    'skills' => $skills,
    'hasskills' => (bool) $skills,
    'students' => $students,
    'hasstudents' => (bool) $students,
]);
echo html_writer::end_div();
echo $OUTPUT->footer();
