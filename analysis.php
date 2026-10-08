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
 * Quizbot Analysis of one quiz, for its teachers (version 1.1): how the class did, by topic, by skill and by question,
 * and who is below the pass mark. Worked out in Moodle from the quiz's own attempts (classes/local/analysis.php).
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_quizbot\local\analysis;
use local_quizbot\local\bloom;
use local_quizbot\local\group_menu;
use local_quizbot\local\page;
use local_quizbot\local\remedial;

require('../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$onlynotes = optional_param('notes', 0, PARAM_BOOL);
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/quiz:viewreports', $context);
require_capability('local/quizbot:viewanalysis', $context);
$quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
$quizname = format_string($cm->name, true, ['context' => $context]);

$url = new moodle_url('/local/quizbot/analysis.php', ['cmid' => $cm->id]);
$PAGE->set_url($onlynotes ? new moodle_url($url, ['notes' => 1]) : $url);
$PAGE->set_title(get_string('analysis', 'local_quizbot') . ': ' . $quizname);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->activityheader->disable();

// The quiz's own group setting decides whom a teacher sees; a year shows its classes together.
$menu = group_menu::for_page($course, $cm, $url);
$groupids = $menu['groups'];
$nogroup = $menu['nogroup'];
if ($menu['year'] !== '') {
    $url->param('year', $menu['year']);
}

if (optional_param('refresh', 0, PARAM_BOOL) && confirm_sesskey()) {
    analysis::for_quiz($cm, $quiz, $groupids, true);
    redirect($url);
}

echo $OUTPUT->header();
echo page::desk_start(get_string('analysis', 'local_quizbot'));

if ($nogroup) {
    echo $OUTPUT->notification(get_string('analysis_nogroup', 'local_quizbot'), \core\output\notification::NOTIFY_INFO);
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

$a = analysis::for_quiz($cm, $quiz, $groupids);
$str = fn (string $key, $param = null): string => get_string($key, 'local_quizbot', $param);
$percent = fn (?float $pct): string => $pct === null ? '–' : format_float($pct, 0) . '%';
$howmany = fn (int $n): string => $str($n === 1 ? 'analysis_onequestion' : 'analysis_questions', $n);
$typename = function (string $qtype) use ($str): string {
    if ($qtype === '') {
        return $str('analysis_random');
    }
    return get_string_manager()->string_exists('pluginname', 'qtype_' . $qtype) ? get_string('pluginname', 'qtype_' . $qtype)
        : $qtype;
};
$topicname = fn (string $topic): string => $topic === '' ? $str('analysis_other') : $topic;
$bar = fn (?float $pct): array => ['width' => $pct === null ? 0 : max(2, (int) round($pct)),
    'weak' => $pct !== null && $pct < $a['pass']];

// The summary.
$gradingurl = new moodle_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'grading']);
$tiles = [
    ['label' => $str('analysis_finished'), 'value' => $a['finished'], 'of' => $str('analysis_of', $a['classsize']),
        'note' => $a['classsize'] ? $str('analysis_ofclass', $percent($a['finished'] / $a['classsize'] * 100)) : ''],
    ['label' => $str('analysis_average'), 'value' => $percent($a['average']), 'of' => '',
        'note' => $a['median'] === null ? '' : $str('analysis_median', $percent($a['median']))],
    ['label' => $str('analysis_passed', $percent($a['pass'])), 'value' => $a['passed'], 'of' => $str('analysis_of', $a['graded']),
        'note' => $a['graded'] ? $percent($a['passed'] / $a['graded'] * 100) : ''],
    ['label' => $str('analysis_below'), 'value' => count($a['below']), 'of' => '', 'note' => '', 'warn' => (bool) $a['below'],
        'anchor' => $a['below'] ? '#local-quizbot-below' : '', 'anchortext' => $str('analysis_seewho')],
    ['label' => $str('analysis_tograde'), 'value' => $a['pending'], 'of' => '', 'note' => '',
        'anchor' => $a['pending'] ? $gradingurl->out(false) : '', 'anchortext' => $str('analysis_grade')],
];

// The tallest bar takes 82% of the chart's height, leaving room for its number above it.
$top = max(1, max(array_column($a['bands'], 'n')));
$bands = [];
foreach ($a['bands'] as $b) {
    $bands[] = ['label' => $b['from'] . '–' . $b['to'] . '%', 'n' => $b['n'], 'below' => $b['below'],
        'height' => $b['n'] ? max(4, (int) round($b['n'] / $top * 82)) : 0];
}

$topics = [];
foreach ($a['topics'] as $t) {
    $topics[] = ['name' => $topicname($t['other'] ? '' : $t['name']), 'other' => $t['other'],
        'questions' => $howmany($t['questions']), 'pct' => $percent($t['pct']), 'few' => $t['few'], 'bar' => $bar($t['pct'])];
}

$skills = [];
foreach ($a['skills'] as $s) {
    $note = $howmany($s['questions']) . ($s['few'] ? ': ' . $str('analysis_fewtosure') : '');
    if ($s['pending']) {
        $note .= ' · ' . $str('analysis_note_pending', $s['pending']);
    }
    $skills[] = ['name' => $s['level'] === '' ? $str('analysis_noskill') : bloom::name($s['level']),
        'pct' => $percent($s['pct']), 'bar' => $bar($s['pct']), 'note' => $note];
}

$difficulty = [];
foreach ($a['difficulty'] as $d) {
    $verdict = $d['verdict'] === '' ? '' : $str('analysis_verdict_' . $d['verdict']);
    foreach ($d['outliers'] as $o) {
        $verdict .= '; ' . $str('analysis_outlier_' . $o['way'], ['n' => $o['slot'], 'pct' => $o['pct']]);
    }
    $difficulty[] = ['level' => $str('difficulty_' . $d['level']), 'questions' => $d['questions'],
        'pct' => $percent($d['pct']), 'verdict' => $verdict];
}

$questions = [];
foreach ($a['questions'] as $q) {
    $notes = array_map(fn ($n) => ['text' => $str('analysis_note_' . $n['key'], isset($n['answer'])
        ? ['pct' => $n['pct'], 'answer' => $n['answer']] : ($n['pct'] ?? ($n['n'] ?? null)))], $q['notes']);
    if ($onlynotes && !$notes) {
        continue;
    }
    $sepweak = $q['sep'] !== null && $q['sep'] < analysis::WEAK_SEPARATION;
    $sep = $q['sep'] === null ? '–' : $str($sepweak ? 'analysis_sep_weak' : 'analysis_sep_good', format_float($q['sep'], 2));
    $questions[] = [
        'slot' => $q['slot'],
        'text' => $q['text'] !== '' ? shorten_text($q['text'], 140) : $str('analysis_random'),
        'label' => $q['label'],
        'type' => $typename($q['qtype']),
        'topic' => $topicname($q['topic']),
        'skill' => $q['bloom'] === '' ? '–' : bloom::name($q['bloom']),
        'pct' => $percent($q['pct']),
        'bar' => $bar($q['pct']),
        'sep' => $sep,
        'sepweak' => $sepweak,
        'notes' => $notes,
        'hasnotes' => (bool) $notes,
        'previewurl' => $q['question'] ? (new moodle_url(
            '/question/bank/previewquestion/preview.php',
            ['id' => $q['question'], 'cmid' => $cm->id]
        ))->out(false) : '',
    ];
}

// Remedial quizzes made from this analysis, and how their students did - on the whole class's figures, whatever
// classes are shown.
$made = !$DB->record_exists('local_quizbot_remedial', ['cmid' => $cm->id]) ? []
    : remedial::made($cm, $groupids ? analysis::for_quiz($cm, $quiz, []) : $a);
$remedials = [];
$stateof = [];
$notstarted = [];
foreach ($made as $m) {
    foreach ($m['members'] as $userid) {
        $tries = $m['tries'][$userid] ?? 0;
        if (!$tries) {
            $notstarted[] = $userid;
        }
        $stateof[$userid] = ['done' => $tries > 0, 'text' => $tries ? $str(
            $tries === 1 ? 'remedial_doneone' : 'remedial_donemany',
            ['n' => $tries, 'pct' => $percent($m['latest'][$userid] ?? null)]
        ) : $str('remedial_notstarted')];
    }
    $remedials[] = [
        'name' => $m['cm']->get_formatted_name(),
        'url' => (new moodle_url('/local/quizbot/analysis.php', ['cmid' => $m['cm']->id]))->out(false),
        'tried' => $str('remedial_tried', ['tried' => count(array_filter($m['tries'])), 'of' => count($m['members'])]),
        'before' => $percent($m['before']),
        'now' => $percent($m['now']),
        'hasnow' => $m['now'] !== null,
        'bar' => $m['now'] === null ? 0 : max(2, (int) round($m['now'])),
    ];
}
$canremedial = $a['below'] && remedial::can_make($context, $course);
$remedialtopics = $canremedial ? array_map(
    fn ($g) => ['topic' => $g['topic'], 'n' => count($g['students'])],
    remedial::groups($a)
) : [];

// The students below the pass mark.
$below = [];
if ($a['below']) {
    $users = $DB->get_records_list(
        'user',
        'id',
        array_column($a['below'], 'user'),
        '',
        'id, ' . implode(', ', \core_user\fields::get_name_fields())
    );
    foreach ($a['below'] as $b) {
        if (!isset($users[$b['user']])) {
            continue;
        }
        $below[] = [
            'id' => $b['user'],
            'name' => fullname($users[$b['user']]),
            'profileurl' => (new moodle_url('/user/view.php', ['id' => $b['user'], 'course' => $course->id]))->out(false),
            'score' => $percent($b['score']),
            'revise' => $b['revise'] ?? '–',
            'when' => userdate($b['timefinish'], get_string('strftimedatetimeshort', 'langconfig')),
            'reviewurl' => (new moodle_url('/mod/quiz/review.php', ['attempt' => $b['attemptid']]))->out(false),
            'remedial' => $stateof[$b['user']]['text'] ?? '–',
            'remedialdone' => !empty($stateof[$b['user']]['done']),
        ];
    }
}
$messaging = !empty($CFG->messaging) && has_capability('moodle/course:bulkmessaging', context_course::instance($course->id));
$canmessage = $messaging && $below;
$notstarted = array_values(array_unique($notstarted));
if ($messaging && ($below || $notstarted)) {
    $PAGE->requires->js_call_amd('local_quizbot/analysis', 'init');
}

$ago = max(0, (int) round((time() - $a['time']) / 60));
echo $OUTPUT->render_from_template('local_quizbot/analysis', [
    'quizname' => $quizname,
    'groupmenu' => $menu['html'],
    'updated' => $ago < 1 ? $str('analysis_updatednow') : $str('analysis_updated', $ago),
    'refreshurl' => (new moodle_url($url, ['refresh' => 1, 'sesskey' => sesskey()]))->out(false),
    'passnote' => $a['passdefault'] ? $str('analysis_passdefault', analysis::DEFAULT_PASS) : '',
    'hasattempts' => $a['finished'] > 0,
    'ungradednote' => $a['finished'] > $a['graded'] ? $str('analysis_ungraded', $a['finished'] - $a['graded']) : '',
    'tiles' => $tiles,
    'bands' => $bands,
    'pass' => $percent($a['pass']),
    'topics' => $topics,
    'hastopics' => (bool) $topics,
    'skills' => $skills,
    'hasskills' => (bool) array_filter($a['skills'], fn ($s) => $s['level'] !== ''),
    'difficulty' => $difficulty,
    'hasdifficulty' => (bool) $difficulty,
    'questions' => $questions,
    'onlynotes' => $onlynotes,
    'notesurl' => (new moodle_url($url, ['notes' => $onlynotes ? 0 : 1]))->out(false),
    'below' => $below,
    'hasbelow' => (bool) $below,
    'belowcount' => count($below),
    'canmessage' => $canmessage,
    'userids' => json_encode(array_column($below, 'id')),
    'minanswers' => analysis::MIN_ANSWERS,
    'canremedial' => $canremedial,
    'remedialurl' => (new moodle_url('/local/quizbot/remedial.php', ['cmid' => $cm->id]))->out(false),
    'remedialtopics' => $remedialtopics,
    'hasremedialtopics' => (bool) $remedialtopics,
    'remedials' => $remedials,
    'hasremedials' => (bool) $remedials,
    'remedialcol' => (bool) $made,
    'cannudge' => $messaging && $notstarted,
    'nudgeids' => json_encode($notstarted),
    'nudgelabel' => $str('remedial_remind', count($notstarted)),
]);
echo html_writer::end_div();
echo $OUTPUT->footer();
