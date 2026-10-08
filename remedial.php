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
 * Make remedial quizzes, from a quiz's Quizbot Analysis: who gets which quiz, Quizbot writes them, the teacher checks
 * the questions, sets the quizzes up, and they are made - each seen only by its own students. Plain forms: no script
 * needed, any theme. The work is in classes/local/remedial.php.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_quizbot\local\analysis;
use local_quizbot\local\api_exception;
use local_quizbot\local\group_menu;
use local_quizbot\local\job;
use local_quizbot\local\licence;
use local_quizbot\local\page;
use local_quizbot\local\question_editor;
use local_quizbot\local\remedial;
use local_quizbot\local\review;
use local_quizbot\local\source_finder;

require('../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

$cmid = required_param('cmid', PARAM_INT);
$run = optional_param('run', '', PARAM_ALPHANUM);
$step = optional_param('step', 'plan', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$tab = optional_param('t', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/quiz:viewreports', $context);
require_capability('local/quizbot:viewanalysis', $context);
if (!remedial::can_make($context, $course)) {
    throw new required_capability_exception($context, 'local/quizbot:generate', 'nopermissions', '');
}
$quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
$quizname = format_string($cm->name, true, ['context' => $context]);
$step = in_array($step, ['plan', 'run', 'review', 'edit', 'setup'], true) ? $step : 'plan';

$baseurl = new moodle_url('/local/quizbot/remedial.php', ['cmid' => $cm->id]);
$analysisurl = new moodle_url('/local/quizbot/analysis.php', ['cmid' => $cm->id]);
$PAGE->set_url(new moodle_url($baseurl, ['step' => $step] + ($run !== '' ? ['run' => $run] : [])));
$PAGE->set_title(get_string('remedial_title', 'local_quizbot') . ': ' . $quizname);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->activityheader->disable();
$PAGE->navbar->add(get_string('analysis', 'local_quizbot'), $analysisurl);
$PAGE->navbar->add(get_string('remedial_title', 'local_quizbot'));

$error = \core\output\notification::NOTIFY_ERROR;
$str = fn (string $key, $param = null): string => get_string($key, 'local_quizbot', $param);
$percent = fn (?float $pct): string => $pct === null ? '–' : format_float($pct, 0) . '%';
$at = function (string $where, array $params = []) use ($baseurl, $run): moodle_url {
    return new moodle_url($baseurl, ['step' => $where] + ($run !== '' ? ['run' => $run] : []) + $params);
};
$finish = function (string $html = '') use ($OUTPUT): void {
    echo $html;
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
};

// The steps: who and what, then checking the questions (while they are written, too), then setting the quizzes up.
$stepnumber = ['plan' => 1, 'run' => 2, 'review' => 2, 'edit' => 2, 'setup' => 3][$step];
$steps = [];
foreach ([1 => 'remedial_step_plan', 2 => 'remedial_step_review', 3 => 'remedial_step_setup'] as $number => $label) {
    $steps[] = ['number' => $number, 'label' => $str($label), 'done' => $number < $stepnumber,
        'current' => $number === $stepnumber];
}

// ----------------------------------------------------------------
// Step 1: who gets which quiz. Worked out from the analysis of the classes chosen there.
if ($step === 'plan') {
    $menu = group_menu::for_page($course, $cm, $analysisurl);
    $a = analysis::for_quiz($cm, $quiz, $menu['groups']);
    $groups = $menu['nogroup'] ? [] : remedial::groups($a);
    $kinds = remedial::kinds($a);
    $qids = array_values(array_filter(array_map('intval', array_column($a['questions'], 'question'))));
    $questions = analysis::questions($qids);
    $levels = ['primary', 'secondary', 'university', 'professional'];

    if ($action === 'write' && data_submitted() && confirm_sesskey()) {
        $checked = array_flip(optional_param_array('s', [], PARAM_INT));
        $moves = optional_param_array('m', [], PARAM_ALPHANUM);
        $numbers = optional_param_array('n', [], PARAM_INT);
        $offered = array_diff(job::TYPES, remedial::LEFT_OUT);
        $chosen = array_values(array_intersect($offered, optional_param_array('k', [], PARAM_ALPHA)));
        $level = optional_param('level', 'secondary', PARAM_ALPHA);
        $level = in_array($level, $levels, true) ? $level : 'secondary';
        if (!$chosen) {
            redirect($at('plan'), $str('remedial_nokinds'), null, $error);
        }
        $bykey = array_column($groups, null, 'key');
        $students = [];
        foreach ($groups as $g) {
            foreach ($g['students'] as $s) {
                if (isset($checked[$s['user']])) {
                    $to = $moves[$s['user']] ?? '';
                    $students[isset($bykey[$to]) ? $to : $g['key']][] = $s['user'];
                }
            }
        }
        if (!$students) {
            redirect($at('plan'), $str('remedial_nostudents'), null, $error);
        }
        $sources = source_finder::by_key(source_finder::for_course($course, (int) $cm->id));
        $weights = [];
        foreach ($chosen as $kind) {
            $weights[$kind] = $kinds[$kind] ?? 1;
        }
        $plan = [];
        foreach ($groups as $g) {
            if (empty($students[$g['key']])) {
                continue;
            }
            $n = max(remedial::MIN_QUESTIONS, min(remedial::MAX_QUESTIONS, (int) ($numbers[$g['key']] ?? remedial::QUESTIONS)));
            $material = remedial::material($questions, $g['topic'], $sources);
            $plan[] = ['topic' => $g['topic'], 'students' => $students[$g['key']], 'counts' => remedial::counts($weights, $n),
                'material' => $material, 'extra' => remedial::extra_sources($questions, $g['topic'], $quizname, $material !== '')];
        }
        try {
            $run = remedial::start($course, $cm, $plan, $level);
        } catch (api_exception $e) {
            if (in_array($e->errorcode, licence::BLOCKING, true)) {
                licence::forget();
            }
            redirect($at('plan'), $e->getMessage(), null, $error);
        }
        redirect(new moodle_url($baseurl, ['step' => 'run', 'run' => $run]));
    }

    echo $OUTPUT->header();
    echo page::desk_start($str('remedial_title'));
    $status = licence::status();
    if (licence::blocks($status)) {
        echo $OUTPUT->notification(licence::blocked_text($status), \core\output\notification::NOTIFY_WARNING);
        $finish($OUTPUT->single_button($analysisurl, $str('remedial_back'), 'get'));
    }
    if (!$groups) {
        echo $OUTPUT->notification($str('remedial_none'), \core\output\notification::NOTIFY_INFO);
        $finish($OUTPUT->single_button($analysisurl, $str('remedial_back'), 'get'));
    }
    $users = $DB->get_records_list(
        'user',
        'id',
        array_merge(...array_map(fn ($g) => array_column($g['students'], 'user'), $groups)),
        '',
        'id, ' . implode(', ', \core_user\fields::get_name_fields())
    );
    $sources = source_finder::by_key(source_finder::for_course($course, (int) $cm->id));
    $cards = [];
    foreach ($groups as $g) {
        $material = remedial::material($questions, $g['topic'], $sources);
        $list = [];
        foreach ($g['students'] as $s) {
            $list[] = ['id' => $s['user'], 'name' => isset($users[$s['user']]) ? fullname($users[$s['user']]) : '#' . $s['user'],
                'group' => $g['key'],
                'score' => $percent($s['score']),
                'hasothers' => count($groups) > 1,
                'others' => array_values(array_map(
                    fn ($o) => ['key' => $o['key'], 'topic' => $o['topic']],
                    array_filter($groups, fn ($o) => $o['key'] !== $g['key'])
                ))];
        }
        $cards[] = [
            'key' => $g['key'],
            'topic' => $g['topic'],
            'material' => $material !== '' ? $sources[$material]['name'] : '',
            'nomaterial' => $material === '',
            'pct' => $percent($g['pct']),
            'students' => $list,
            'single' => count($list) === 1,
            'n' => remedial::QUESTIONS,
        ];
    }
    $kindlist = [];
    foreach (job::TYPES as $type) {
        $kindlist[] = ['id' => $type, 'label' => get_string('type_' . $type, 'local_quizbot'), 'checked' => isset($kinds[$type]),
            'disabled' => in_array($type, remedial::LEFT_OUT, true)];
    }
    $level = 'secondary';
    $last = $DB->get_records_select(
        'local_quizbot_job',
        'cmid = ? AND remedial IS NULL',
        [$cm->id],
        'id DESC',
        'id, options',
        0,
        1
    );
    if ($last) {
        $level = (string) (json_decode((string) reset($last)->options, true)['level'] ?? 'secondary');
    }
    $belowcount = array_sum(array_map(fn ($g) => count($g['students']), $groups));
    $PAGE->requires->js_call_amd('local_quizbot/remedialplan', 'init');
    echo $OUTPUT->render_from_template('local_quizbot/remedial_plan', [
        'actionurl' => $baseurl->out(false),
        'sesskey' => sesskey(),
        'cmid' => $cm->id,
        'steps' => $steps,
        'subtitle' => $str($belowcount === 1 ? 'remedial_subtitleone' : 'remedial_subtitle', ['quiz' => $quizname,
            'n' => $belowcount, 'pass' => $percent($a['pass'])]),
        'leftnote' => count($a['below']) > $belowcount ? $str('remedial_left', count($a['below']) - $belowcount) : '',
        'groups' => $cards,
        'kinds' => $kindlist,
        'levels' => array_map(fn ($l) => ['value' => $l, 'label' => get_string('level_' . $l, 'local_quizbot'),
            'selected' => $l === $level], $levels),
        'min' => remedial::MIN_QUESTIONS,
        'max' => remedial::MAX_QUESTIONS,
        'summary' => $str('remedial_summary', ['quizzes' => count($cards), 'questions' => count($cards) * remedial::QUESTIONS]),
        // With script the summary follows every tick, move and number (local_quizbot/remedialplan).
        'summarytemplate' => $str('remedial_summary', ['quizzes' => '{quizzes}', 'questions' => '{questions}']),
        'licenceleft' => licence::left_text($status),
        'cancelurl' => $analysisurl->out(false),
    ]);
    $finish();
}

// ----------------------------------------------------------------
// The later steps belong to one go of runs.
$jobs = remedial::jobs($cm->id, $USER->id, $run);
if (!$jobs) {
    redirect(new moodle_url($baseurl, ['step' => 'plan']));
}
// Under way: sent to Quizbot, or waiting for its turn to be sent.
$running = array_filter($jobs, fn ($j) => in_array($j->status, [job::STATUS_DRAFT, job::STATUS_RUNNING], true));
$inreview = array_values(array_filter($jobs, fn ($j) => $j->status === job::STATUS_REVIEW));
if (!$running && !$inreview) {
    // Nothing came back at all: back to the first step, with the reason. Made already, or given up: the analysis.
    $reasons = array_values(array_filter(array_map(
        fn ($j) => $j->status === job::STATUS_DISCARDED ? (string) $j->error : '',
        $jobs
    )));
    if ($reasons && !array_filter($jobs, fn ($j) => $j->status === job::STATUS_SAVED)) {
        redirect(new moodle_url($baseurl, ['step' => 'plan']), $str('remedial_nonewritten', $reasons[0]), null, $error);
    }
    redirect($analysisurl);
}
if ($running && $step !== 'run') {
    redirect($at('run'));
}
$topicof = fn (\stdClass $j): string => (string) ($j->optionsdata['remedial']['topic'] ?? '');

// While Quizbot writes: one line per quiz; the page asks again by itself every few seconds.
if ($step === 'run') {
    // The quizzes still waiting for their turn go now, as far as Quizbot takes them.
    remedial::send_waiting($course, $cm, $jobs);
    $rows = [];
    foreach ($jobs as $j) {
        $state = remedial::poll($j);
        $rows[] = ['label' => $str('remedial_writing', ['topic' => $topicof($j), 'n' => job::total($j->optionsdata)]),
            'done' => $state['state'] === 'done', 'now' => $state['state'] === 'now', 'problem' => $state['state'] === 'problem',
            'waiting' => $state['state'] === 'waiting', 'note' => $state['note']];
    }
    if (!array_filter($rows, fn ($r) => $r['now'] || $r['waiting'])) {
        redirect($at('review'));
    }
    $PAGE->set_periodic_refresh_delay(4);
    echo $OUTPUT->header();
    echo page::desk_start($str('remedial_title'));
    $finish($OUTPUT->render_from_template('local_quizbot/progress', ['rows' => $rows, 'problem' => '',
        'quizurl' => $analysisurl->out(false), 'steps' => $steps]));
}

// The quiz in hand on the review and edit pages.
$current = $inreview[0];
foreach ($inreview as $j) {
    if ((int) $j->id === $tab) {
        $current = $j;
    }
}
$hidden = [['name' => 'run', 'value' => $run], ['name' => 't', 'value' => $current->id]];
$editdraft = null;
$editerrors = [];

if ($action !== '' && data_submitted() && confirm_sesskey()) {
    $card = function (int $i) use ($at, $current): moodle_url {
        $url = $at('review', ['t' => $current->id]);
        $url->set_anchor('local-quizbot-card' . $i);
        return $url;
    };
    $result = job::result($current);
    if ($step === 'review') {
        $result = review::keep_ticks($current, optional_param_array('q', [], PARAM_INT));
    }
    if ($action === 'discard') {
        foreach ($jobs as $j) {
            if (in_array($j->status, [job::STATUS_RUNNING, job::STATUS_REVIEW], true)) {
                remedial::give_up($j);
            }
        }
        redirect($analysisurl, $str('remedial_discarded'), null, \core\output\notification::NOTIFY_INFO);
    }
    if (preg_match('/^tab-(\d+)$/', $action, $m)) {
        redirect($at('review', ['t' => (int) $m[1]]));
    }
    if ($action === 'next') {
        redirect($at('setup'));
    }
    if ($action === 'back') {
        redirect($at('review', ['t' => $current->id]));
    }
    if (preg_match('/^edit-(\d+)$/', $action, $m) && isset($result['questions'][(int) $m[1]])) {
        redirect($at('edit', ['t' => $current->id, 'qi' => (int) $m[1]]));
    }
    if (preg_match('/^rewrite-(\d+)$/', $action, $m) && isset($result['questions'][(int) $m[1]])) {
        try {
            review::rewrite($current, (int) $m[1]);
            redirect(
                $card((int) $m[1]),
                get_string('rewritten', 'local_quizbot', (int) $m[1] + 1),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (api_exception $e) {
            redirect($card((int) $m[1]), $e->getMessage(), null, $error);
        }
    }
    if ($action === 'editcancel' || $action === 'editsave') {
        $i = required_param('qi', PARAM_INT);
        if (!isset($result['questions'][$i]) || $action === 'editcancel') {
            redirect($card($i));
        }
        [$edited, $editerrors] = question_editor::from_form($result['questions'][$i]);
        if (!$editerrors) {
            unset($edited['rewritten']);
            $result['questions'][$i] = ['edited' => true] + $edited;
            $current->result = json_encode($result);
            job::save($current);
            redirect($card($i), get_string('editsaved', 'local_quizbot', $i + 1), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        $editdraft = $edited;
        $step = 'edit';
    }
    if ($action === 'create' && $step === 'setup') {
        $names = optional_param_array('name', [], PARAM_TEXT);
        $sections = array_map(fn ($s) => (int) $s->section, get_fast_modinfo($course)->get_section_info_all());
        $section = optional_param('section', (int) $cm->sectionnum, PARAM_INT);
        $behaviour = optional_param('behaviour', 'interactive', PARAM_ALPHA);
        $due = optional_param('hasdue', 0, PARAM_BOOL) ? optional_param('due', '', PARAM_RAW_TRIMMED) : '';
        $duetime = 0;
        if ($due !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $due, $d)) {
            $duetime = make_timestamp((int) $d[1], (int) $d[2], (int) $d[3], 23, 59);
        }
        $settings = [
            'section' => in_array($section, $sections, true) ? $section : (int) $cm->sectionnum,
            'behaviour' => in_array($behaviour, ['interactive', 'deferredfeedback'], true) ? $behaviour : 'interactive',
            'attempts' => max(0, min(3, optional_param('attempts', 0, PARAM_INT))),
            'counted' => optional_param('counted', 0, PARAM_BOOL),
            'due' => $duetime,
            'keep' => optional_param('keep', 'quiz', PARAM_ALPHA) === 'shared' ? 'shared' : 'quiz',
        ];
        if (empty($CFG->enableavailability)) {
            redirect($at('setup'), $str('remedial_noavailability'), null, $error);
        }
        $made = [];
        try {
            foreach ($inreview as $j) {
                $name = trim((string) ($names[$j->id] ?? ''));
                $name = $name !== '' ? $name : $str('remedial_name', $topicof($j));
                if ($quizmade = remedial::make($course, $cm, $j, ['name' => core_text::substr($name, 0, 255)] + $settings)) {
                    $made[] = $quizmade;
                }
            }
        } catch (Throwable $e) {
            debugging('Quizbot: a remedial quiz could not be made: ' . $e->getMessage(), DEBUG_DEVELOPER);
            redirect($at('setup'), $str('remedial_failed', count($made)), null, $error);
        }
        licence::forget();      // Fewer questions are left now.
        $sent = optional_param('message', 0, PARAM_BOOL)
            ? remedial::tell($made, core_text::substr(optional_param('messagetext', '', PARAM_TEXT), 0, 2000)) : 0;
        $students = array_sum(array_map(fn ($q) => count($q->students), $made));
        redirect($analysisurl, $str('remedial_made', ['quizzes' => count($made), 'students' => $students])
            . ($sent ? ' ' . $str('remedial_sent', $sent) : ''), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if (!$editdraft) {
        redirect($at($step === 'setup' ? 'setup' : 'review', ['t' => $current->id]));
    }
}

echo $OUTPUT->header();
echo page::desk_start($str('remedial_title'));
$tickedall = 0;
foreach ($inreview as $j) {
    $r = job::result($j);
    $tickedall += count($r['questions']) - count($r['unticked']);
}
$students = function (\stdClass $j) use ($DB): array {
    $ids = $j->optionsdata['remedial']['students'] ?? [];
    $fields = 'id, ' . implode(', ', \core_user\fields::get_name_fields());
    $users = $ids ? $DB->get_records_list('user', 'id', $ids, '', $fields) : [];
    return array_values(array_map(fn ($u) => fullname($u), $users));
};
$failed = array_values(array_filter($jobs, fn ($j) => $j->status === job::STATUS_DISCARDED && $j->error));
foreach ($failed as $j) {
    echo $OUTPUT->notification(
        $str('remedial_nonefor', ['topic' => $topicof($j), 'reason' => $j->error]),
        \core\output\notification::NOTIFY_WARNING
    );
}

if ($step === 'review') {
    $tabs = [];
    foreach ($inreview as $j) {
        $n = count($j->optionsdata['remedial']['students'] ?? []);
        $tabs[] = ['id' => $j->id, 'name' => $topicof($j), 'current' => $j->id === $current->id,
            'note' => $str($n === 1 ? 'remedial_tabone' : 'remedial_tab', ['students' => $n,
                'questions' => count(job::result($j)['questions'])])];
    }
    $PAGE->requires->js_call_amd('local_quizbot/review', 'init');
    $view = review::view($current, $context);
    if ($view['haschips']) {
        $PAGE->requires->js_call_amd('local_quizbot/review', 'filter');
    }
    $finish($OUTPUT->render_from_template('local_quizbot/review', $view + [
        'actionurl' => $baseurl->out(false),
        'sesskey' => sesskey(),
        'cmid' => $cm->id,
        'hidden' => $hidden,
        'hastabs' => true,
        'tabs' => $tabs,
        'tabnote' => $str('remedial_for', implode(', ', $students($current))),
        'remedial' => true,
        'remedialtotal' => $str('remedial_ticked', ['questions' => $tickedall, 'quizzes' => count($inreview)]),
        'quizname' => $quizname,
        'steps' => $steps,
    ]));
}

if ($step === 'edit') {
    $result = job::result($current);
    $i = optional_param('qi', -1, PARAM_INT);
    if (!$editdraft && !isset($result['questions'][$i])) {
        redirect($at('review', ['t' => $current->id]));
    }
    $q = $editdraft ?? $result['questions'][$i];
    $finish($OUTPUT->render_from_template('local_quizbot/edit', question_editor::for_template($q, $editerrors) + [
        'actionurl' => $baseurl->out(false),
        'sesskey' => sesskey(),
        'cmid' => $cm->id,
        'hidden' => $hidden,
        'index' => $i,
        'number' => $i + 1,
        'typelabel' => get_string('type_' . $q['type'], 'local_quizbot'),
        'source' => $result['names'][$q['source'] ?? ''] ?? '',
        'steps' => $steps,
    ]));
}

// Step 3: how the quizzes are set up.
$quizzes = [];
foreach ($inreview as $j) {
    $r = job::result($j);
    $ticked = count($r['questions']) - count($r['unticked']);
    $n = count($j->optionsdata['remedial']['students'] ?? []);
    $quizzes[] = ['id' => $j->id, 'topic' => $topicof($j), 'name' => $str('remedial_name', $topicof($j)),
        'note' => $str($n === 1 ? 'remedial_madenoteone' : 'remedial_madenote', ['students' => $n, 'questions' => $ticked,
            'group' => $str('remedial_groupname', $topicof($j))]), 'empty' => $ticked === 0];
}
$sectionlist = [];
foreach (get_fast_modinfo($course)->get_section_info_all() as $section) {
    $sectionlist[] = ['value' => $section->section, 'label' => get_section_name($course, $section),
        'selected' => (int) $section->section === (int) $cm->sectionnum];
}
echo $OUTPUT->render_from_template('local_quizbot/remedial_setup', [
    'actionurl' => $baseurl->out(false),
    'sesskey' => sesskey(),
    'cmid' => $cm->id,
    'hidden' => $hidden,
    'steps' => $steps,
    'quizzes' => $quizzes,
    'sections' => $sectionlist,
    'canmessage' => !empty($CFG->messaging),
    'messagetext' => $str('remedial_message'),
    'noavailability' => empty($CFG->enableavailability),
    'summary' => $str('remedial_createsummary', ['quizzes' => count(array_filter($quizzes, fn ($q) => !$q['empty'])),
        'students' => array_sum(array_map(fn ($j) => count($j->optionsdata['remedial']['students'] ?? []), $inreview))]),
    'createlabel' => $str('remedial_create', count(array_filter($quizzes, fn ($q) => !$q['empty']))),
]);
$finish();
