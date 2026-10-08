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
 * "My progress" in a course (1.1): a student's own results across the course's quizzes, by topic and by skill, from
 * their latest answer to each question - and only what each quiz lets them see. Teachers may open a student's page
 * from the result card or the analysis (userid).
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_quizbot\local\bloom;
use local_quizbot\local\page;
use local_quizbot\local\student;

require('../../config.php');

$courseid = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
$own = !$userid || $userid == $USER->id;
if ($own) {
    $userid = (int) $USER->id;
    require_capability('local/quizbot:viewownprogress', $context);
} else {
    // A teacher looking at one student: the student must be in the course, and in a group the teacher may see.
    require_capability('local/quizbot:viewanalysis', $context);
    if (!is_enrolled($context, $userid, '', true)) {
        throw new moodle_exception('notenrolled', 'core', '', fullname(core_user::get_user($userid, '*', MUST_EXIST)));
    }
    if (
        groups_get_course_groupmode($course) == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)
        && !array_intersect(
            array_keys(groups_get_all_groups($course->id, $USER->id)),
            array_keys(groups_get_all_groups($course->id, $userid))
        )
    ) {
        throw new required_capability_exception($context, 'moodle/site:accessallgroups', 'nopermissions', '');
    }
}
$user = core_user::get_user($userid, '*', MUST_EXIST);

$url = new moodle_url('/local/quizbot/myprogress.php', ['id' => $course->id] + ($own ? [] : ['userid' => $userid]));
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$title = $own ? get_string('myprogress', 'local_quizbot') : get_string('myprogress_of', 'local_quizbot', fullname($user));
$PAGE->set_title($title . ': ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add($title);

$p = student::gather_progress($course, $userid);
$str = fn (string $key, $param = null): string => get_string($key, 'local_quizbot', $param);
$percent = fn (?float $pct): string => $pct === null ? '–' : format_float($pct, 0) . '%';
$words = ['strong' => 'card_strong', 'getting' => 'card_getting', 'revise' => 'card_revisetopic'];
$right = fn (array $t): string => $str('card_right', ['right' => $t['right'], 'total' => $t['total']]);
$bar = fn (?float $pct): array => ['width' => $pct === null ? 0 : max(2, (int) round($pct)),
    'weak' => $pct !== null && $pct < student::GETTING];

$next = '';
if ($p['next']) {
    $next = $p['next']['timeopen'] > time()
        ? $str('myprogress_nextfrom', ['name' => $p['next']['name'], 'date' => userdate(
            $p['next']['timeopen'],
            get_string('strftimedateshort', 'langconfig')
        )])
        : $str('myprogress_next', $p['next']['name']);
}
$strongest = $p['strongest'] && (!$p['revise'] || $p['strongest']['name'] !== $p['revise']['name']) ? $p['strongest'] : null;
$tiles = [
    ['label' => $str('myprogress_done'), 'value' => $p['done'], 'of' => $str('analysis_of', $p['quizcount']), 'note' => $next],
    ['label' => $str($own ? 'myprogress_average' : 'myprogress_average_other'), 'value' => $percent($p['average']),
        // The trend only from two quizzes on: one number twice says nothing.
        'of' => '', 'note' => count($p['trend']) > 1 ? implode(' → ', array_map($percent, $p['trend'])) : ''],
];
if ($strongest) {
    $tiles[] = ['label' => $str('myprogress_strongest'), 'value' => '', 'text' => $strongest['name'], 'of' => '',
        'note' => $right($strongest)];
}
if ($p['revise']) {
    $tiles[] = ['label' => $str('myprogress_revise'), 'value' => '', 'text' => $p['revise']['name'], 'of' => '',
        'note' => $right($p['revise']), 'warn' => true];
}

$topics = [];
foreach ($p['topics'] as $t) {
    $material = student::material_url($course, $userid, $t['sourcecmid'], $t['source']);
    $topics[] = ['name' => $t['name'], 'pct' => $percent($t['pct']), 'bar' => $bar($t['pct']),
        'word' => $str($words[$t['word']]), 'wordclass' => 'is-' . $t['word'], 'righttext' => $right($t),
        'materialurl' => $material ? $material->out(false) : '', 'materialname' => $t['source']];
}

$skills = [];
foreach ($p['skills'] as $s) {
    $skills[] = ['name' => bloom::name($s['level']), 'pct' => $percent($s['pct']), 'bar' => $bar($s['pct'])];
}
$weakskill = $p['skills'] ? array_reduce($p['skills'], fn ($w, $s) => !$w || $s['pct'] < $w['pct'] ? $s : $w) : null;

$quizzes = [];
foreach (array_reverse($p['quizzes']) as $q) {
    $quizzes[] = ['name' => $q['quiz']['name'], 'url' => (new moodle_url('/mod/quiz/view.php', ['id' => $q['quiz']['cmid']]))
        ->out(false), 'when' => userdate($q['when'], get_string('strftimedateshort', 'langconfig')),
        'pct' => $q['pct'] === null ? $str('myprogress_hidden') : $percent($q['pct']), 'bar' => $bar($q['pct']),
        'hidden' => $q['pct'] === null];
}

$again = [];
foreach ($p['again'] as $a) {
    $link = $p['links'][$a['attempt']] ?? null;
    $reviewurl = '';
    if ($link && quiz_get_review_options($link['quiz'], $link['row'], context_module::instance($link['cmid']))->attempt) {
        $review = new moodle_url('/mod/quiz/review.php', ['attempt' => $a['attempt'], 'showall' => 1]);
        $review->set_anchor('question-' . $link['row']->uniqueid . '-' . $a['slot']);
        $reviewurl = $review->out(false);
    }
    $again[] = ['where' => $str('myprogress_question', ['quiz' => format_string($link['quiz']->name ?? ''), 'slot' => $a['slot']]),
        'text' => shorten_text($p['questions'][$a['question']]['text'] ?? '', 160), 'reviewurl' => $reviewurl];
}

echo $OUTPUT->header();
echo page::desk_start($title);
echo $OUTPUT->render_from_template('local_quizbot/myprogress', [
    'basis' => $str($own ? 'myprogress_basis' : 'myprogress_basis_other'),
    'hasdone' => $p['done'] > 0,
    'tiles' => $tiles,
    'topics' => $topics,
    'hastopics' => (bool) $topics,
    'skills' => $skills,
    'hasskills' => (bool) $skills,
    'skillexplain' => $weakskill ? $str('skillexplain_' . $weakskill['level']) : '',
    'quizzes' => $quizzes,
    'again' => $again,
    'hasagain' => (bool) $again,
    // A remedial quiz made for this student and not finished yet: first on the page.
    'remedials' => \local_quizbot\local\remedial::for_student($course, $userid),
]);
echo html_writer::end_div();
echo $OUTPUT->footer();
