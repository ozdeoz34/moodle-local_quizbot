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
 * The Quizbot wizard for one quiz: choose the sources, choose the questions, (soon) review and add them.
 *
 * Every step is an ordinary page built with Moodle's own output, so it takes each site's theme as it is.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_quizbot\form\upload_form;
use local_quizbot\local\api;
use local_quizbot\local\api_exception;
use local_quizbot\local\bloom;
use local_quizbot\local\course_files;
use local_quizbot\local\job;
use local_quizbot\local\licence;
use local_quizbot\local\link_reader;
use local_quizbot\local\page;
use local_quizbot\local\question_editor;
use local_quizbot\local\question_saver;
use local_quizbot\local\review;
use local_quizbot\local\sender;
use local_quizbot\local\source_finder;

require('../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

$cmid = required_param('cmid', PARAM_INT);
$step = optional_param('step', 'sources', PARAM_ALPHA);
$tab = optional_param('tab', 'course', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/quiz:manage', $context);
require_capability('local/quizbot:generate', $context);

$step = in_array($step, ['sources', 'options', 'run', 'review', 'edit'], true) ? $step : 'sources';
$tabs = ['course', 'upload', 'link', 'text'];
$tab = in_array($tab, $tabs, true) ? $tab : 'course';

$baseurl = new moodle_url('/local/quizbot/generate.php', ['cmid' => $cm->id]);
$quizurl = new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]);
$quizname = format_string($cm->name, true, ['context' => $context]);

$PAGE->set_url(new moodle_url($baseurl, ['step' => $step] + ($step === 'sources' ? ['tab' => $tab] : [])));
$PAGE->set_title(get_string('wizardtitle', 'local_quizbot', $quizname));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->activityheader->disable();

// A quiz that students have attempted cannot take new questions: say so instead of starting.
if (quiz_has_attempts($cm->instance)) {
    echo $OUTPUT->header();
    echo page::desk_start(get_string('wizardtitle', 'local_quizbot', $quizname));
    echo $OUTPUT->notification(get_string('hasattempts', 'local_quizbot'), \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->single_button($quizurl, get_string('backtoquiz', 'local_quizbot'), 'get');
    echo html_writer::end_div();
    echo $OUTPUT->footer();
    exit;
}

$job = job::current($cm->id, $course->id, $USER->id);
$sections = source_finder::for_course($course, (int) $cm->id);
$bykey = source_finder::by_key($sections);
$fs = get_file_storage();
$uploadoptions = upload_form::options((int) $course->maxbytes);
$error = \core\output\notification::NOTIFY_ERROR;

// A run that has been sent cannot be changed any more: always show where it is. Before that, there is nothing to
// show on the later pages.
$isaction = $action !== '' && data_submitted();
if ($job->status === job::STATUS_RUNNING && $step !== 'run') {
    redirect(new moodle_url($baseurl, ['step' => 'run']));
}
if ($job->status === job::STATUS_REVIEW && !in_array($step, ['review', 'edit'], true) && !$isaction) {
    redirect(new moodle_url($baseurl, ['step' => 'review']));
}
if ($job->status === job::STATUS_DRAFT && in_array($step, ['run', 'review', 'edit'], true)) {
    redirect(new moodle_url($baseurl, ['step' => 'options']));
}
if ($step === 'edit' && !isset(job::result($job)['questions'][optional_param('qi', -1, PARAM_INT)])) {
    redirect(new moodle_url($baseurl, ['step' => 'review']));
}

// What is chosen so far, in the words shown to the teacher.
$chosen = function () use ($job, $bykey, $fs, $context): array {
    $list = [];
    foreach ($job->sourcesdata['keys'] as $key) {
        if (isset($bykey[$key])) {
            $list[] = ['name' => $bykey[$key]['name'], 'note' => $bykey[$key]['detail'], 'kind' => $bykey[$key]['kind'],
                'key' => $key];
        }
    }
    foreach ($fs->get_area_files($context->id, 'local_quizbot', 'upload', $job->id, 'filename', false) as $file) {
        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $list[] = ['name' => $file->get_filename(), 'note' => get_string('chosenuploaded', 'local_quizbot'),
            'kind' => source_finder::KINDS[$ext] ?? 'text', 'uploaded' => true];
    }
    $link = $job->sourcesdata['link'];
    if ($link['type'] === 'youtube') {
        $list[] = ['name' => $link['name'], 'note' => get_string('linkdetail_youtube', 'local_quizbot'), 'kind' => 'youtube',
            'link' => true];
    } else if ($link['type'] === 'page') {
        $list[] = ['name' => $link['name'], 'note' => get_string('linkdetail_page', 'local_quizbot', $link['words']),
            'kind' => 'link',
            'link' => true];
    } else if ($link['type'] === 'file' && link_reader::file($context, $job->id)) {
        $list[] = ['name' => $link['name'], 'note' => get_string('linkdetail_file', 'local_quizbot', display_size($link['size'])),
            'kind' => source_finder::KINDS[$link['ext']] ?? 'text', 'link' => true];
    }
    if (trim($job->sourcesdata['text']) !== '') {
        $words = count(preg_split('/\s+/u', trim($job->sourcesdata['text']), -1, PREG_SPLIT_NO_EMPTY));
        $list[] = ['name' => get_string('chosenpasted', 'local_quizbot', $words), 'note' => '', 'kind' => 'text', 'pasted' => true];
    }
    return $list;
};

// An edit that could not be saved: shown again with what was typed (set below).
$editdraft = null;
$editerrors = [];

// ----------------------------------------------------------------
// What was sent with the plain forms.
if ($action !== '' && data_submitted() && confirm_sesskey()) {
    if ($job->status === job::STATUS_REVIEW) {
        $result = job::result($job);
        $card = function (int $i) use ($baseurl): moodle_url {
            $url = new moodle_url($baseurl, ['step' => 'review']);
            $url->set_anchor('local-quizbot-card' . $i);
            return $url;
        };
        // The review page's buttons all send its ticks: keep them, so an edit or a rewrite does not tick everything again.
        if ($step === 'review') {
            $result = review::keep_ticks($job, optional_param_array('q', [], PARAM_INT));
        }
        if ($action === 'discard') {
            // Give these questions up; the next visit starts a fresh run. Quizbot may forget what it read for them.
            try {
                (new api())->saved((string) $job->remoteid, 0);
            } catch (api_exception $e) {
                debugging('Quizbot: could not report the discarded run: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            $job->status = job::STATUS_DISCARDED;
            job::save($job);
            $fs->delete_area_files($context->id, 'local_quizbot', 'upload', $job->id);
            link_reader::forget($context, $job->id);
            redirect(new moodle_url($baseurl, ['step' => 'sources']));
        }
        if (preg_match('/^edit-(\d+)$/', $action, $m) && isset($result['questions'][(int) $m[1]])) {
            redirect(new moodle_url($baseurl, ['step' => 'edit', 'qi' => (int) $m[1]]));
        }
        if (preg_match('/^rewrite-(\d+)$/', $action, $m) && isset($result['questions'][(int) $m[1]])) {
            // A new question of the same kind, from the same source, unlike the ones on the page now.
            $i = (int) $m[1];
            try {
                review::rewrite($job, $i);
                redirect(
                    $card($i),
                    get_string('rewritten', 'local_quizbot', $i + 1),
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
            } catch (api_exception $e) {
                redirect($card($i), $e->getMessage(), null, $error);
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
                $job->result = json_encode($result);
                job::save($job);
                redirect(
                    $card($i),
                    get_string('editsaved', 'local_quizbot', $i + 1),
                    null,
                    \core\output\notification::NOTIFY_SUCCESS
                );
            }
            // Not saved: the edit page is shown again below, with what was typed and what is wrong with it.
            $editdraft = $edited;
        }
        if ($action === 'save') {
            $picked = array_flip(optional_param_array('q', [], PARAM_INT));
            $questions = array_values(array_intersect_key($result['questions'], $picked));
            if (!$questions) {
                redirect(
                    new moodle_url($baseurl, ['step' => 'review']),
                    get_string('errornonepicked', 'local_quizbot'),
                    null,
                    $error
                );
            }
            $added = question_saver::save(
                $questions,
                $course,
                $cm,
                $job->optionsdata['keep'] ?? 'quiz',
                (int) $job->id,
                $result['names'] ?? []
            );
            // Files from outside the course that the teacher ticked: into the quiz's section, hidden from students.
            $infiles = 0;
            $wanted = optional_param_array('addfile', [], PARAM_INT);
            if ($wanted && course_files::can_add($course)) {
                foreach (array_intersect_key(course_files::candidates($context, $job->id), array_flip($wanted)) as $file) {
                    try {
                        course_files::add($course, $cm, $file);
                        $infiles++;
                    } catch (Throwable $e) {
                        debugging(
                            'Quizbot: could not put ' . $file->get_filename() . ' into the course: ' . $e->getMessage(),
                            DEBUG_DEVELOPER
                        );
                    }
                }
            }
            try {
                // Only what was really added counts on the licence.
                (new api())->saved((string) $job->remoteid, $added);
            } catch (api_exception $e) {
                debugging('Quizbot: could not report the added questions: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
            licence::forget();      // Fewer questions are left now.
            $job->status = job::STATUS_SAVED;
            job::save($job);
            $fs->delete_area_files($context->id, 'local_quizbot', 'upload', $job->id);
            link_reader::forget($context, $job->id);
            redirect(new moodle_url('/mod/quiz/edit.php', ['cmid' => $cm->id]), get_string('addedn', 'local_quizbot', $added)
                . ($infiles ? ' ' . get_string($infiles === 1 ? 'addedfile' : 'addedfiles', 'local_quizbot', $infiles)
                    : ''), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if (empty($editdraft)) {
            redirect(new moodle_url($baseurl, ['step' => 'review']));
        }
    }
    if ($action === 'reset' && $job->status === job::STATUS_DRAFT) {
        // The "Start again" button: forget everything chosen for this quiz, uploaded files included.
        $job->sourcesdata = ['keys' => [], 'text' => '', 'link' => link_reader::NONE];
        $job->optionsdata = job::default_options();
        job::save($job);
        $fs->delete_area_files($context->id, 'local_quizbot', 'upload', $job->id);
        link_reader::forget($context, $job->id);
        redirect(new moodle_url($baseurl, ['step' => 'sources', 'tab' => 'course']));
    }
    if ($step === 'sources' && $job->status === job::STATUS_DRAFT) {
        $fromtab = optional_param('fromtab', 'course', PARAM_ALPHA);
        if ($fromtab === 'course') {
            $posted = optional_param_array('sources', [], PARAM_ALPHANUMEXT);
            $job->sourcesdata['keys'] = array_values(array_intersect(array_keys($bykey), $posted));
        } else if ($fromtab === 'text') {
            $job->sourcesdata['text'] = core_text::substr(optional_param('pastetext', '', PARAM_RAW_TRIMMED), 0, 200000);
        } else if ($fromtab === 'link') {
            $typed = core_text::substr(optional_param('link', '', PARAM_RAW_TRIMMED), 0, 2000);
            if ($typed === '') {
                link_reader::forget($context, $job->id);
                $job->sourcesdata['link'] = link_reader::NONE;
            } else if ($typed !== $job->sourcesdata['link']['url'] || $job->sourcesdata['link']['type'] === '') {
                // A new link: open it now, so the teacher learns at once whether Quizbot can read it.
                try {
                    core_php_time_limit::raise(120);
                    $job->sourcesdata['link'] = link_reader::read($typed, $context, $job->id);
                } catch (moodle_exception $e) {
                    // Keep what was typed in the box, so it can be corrected; it is not a source until it can be read.
                    $job->sourcesdata['link'] = ['url' => $typed] + link_reader::NONE;
                    job::save($job);
                    redirect(new moodle_url($baseurl, ['step' => 'sources', 'tab' => 'link']), $e->getMessage(), null, $error);
                }
            }
        }
        job::save($job);
        if (strpos($action, 'tab-') === 0 && in_array(substr($action, 4), $tabs, true)) {
            redirect(new moodle_url($baseurl, ['step' => 'sources', 'tab' => substr($action, 4)]));
        }
        redirect(new moodle_url($baseurl, ['step' => $action === 'next' ? 'options' : 'sources', 'tab' => $fromtab]));
    }
    if ($step === 'options' && $job->status === job::STATUS_DRAFT) {
        $options = $job->optionsdata;
        foreach (job::TYPES as $type) {
            $options['counts'][$type] = max(0, min(job::MAX_QUESTIONS, optional_param('n_' . $type, 0, PARAM_INT)));
        }
        $pick = function (string $name, array $allowed, string $default): string {
            $value = optional_param($name, $default, PARAM_ALPHANUMEXT);
            return in_array($value, $allowed, true) ? $value : $default;
        };
        $options['language'] = $pick('language', array_keys(job::languages()), 'auto');
        $options['level'] = $pick('level', ['primary', 'secondary', 'university', 'professional'], 'secondary');
        $options['difficulty'] = $pick('difficulty', ['mixed', 'easy', 'medium', 'hard'], 'mixed');
        $options['keep'] = $pick('keep', ['quiz', 'shared'], 'quiz');
        $options['feedback'] = optional_param('feedback', 0, PARAM_BOOL) ? 1 : 0;
        $options['spread'] = optional_param('spread', 0, PARAM_BOOL) ? 1 : 0;
        $options['bloomon'] = optional_param('bloomon', 0, PARAM_BOOL) ? 1 : 0;
        $typed = [];
        foreach (bloom::LEVELS as $level) {
            $typed[$level] = optional_param('b_' . $level, 0, PARAM_INT);
        }
        $options['skills'] = bloom::clean($typed);
        $job->optionsdata = $options;
        job::save($job);
        if ($action === 'back') {
            redirect(new moodle_url($baseurl, ['step' => 'sources']));
        }
        $total = job::total($options);
        if ($total < 1) {
            redirect(new moodle_url($baseurl, ['step' => 'options']), get_string('errortotalnone', 'local_quizbot'), null, $error);
        }
        if ($total > job::MAX_QUESTIONS) {
            redirect(
                new moodle_url($baseurl, ['step' => 'options']),
                get_string('errortotalmax', 'local_quizbot', ['max' => job::MAX_QUESTIONS, 'n' => $total]),
                null,
                $error
            );
        }
        foreach (job::MAX_PER_TYPE as $type => $max) {
            if ($options['counts'][$type] > $max) {
                redirect(new moodle_url($baseurl, ['step' => 'options']), get_string(
                    'errortypemax',
                    'local_quizbot',
                    ['max' => $max, 'n' => $options['counts'][$type], 'type' => get_string('type_' . $type, 'local_quizbot')]
                ), null, $error);
            }
        }
        // Bloom's levels: never more than the questions asked for, and only levels the chosen kinds can test.
        if ($options['bloomon']) {
            $skillsum = array_sum($options['skills']);
            if ($skillsum > $total) {
                redirect(new moodle_url($baseurl, ['step' => 'options']), get_string(
                    'bloomover',
                    'local_quizbot',
                    ['skills' => $skillsum, 'total' => $total]
                ), null, $error);
            }
            if ($misfit = bloom::misfit_text($options['counts'], $options['skills'])) {
                redirect(new moodle_url($baseurl, ['step' => 'options']), $misfit, null, $error);
            }
        }
        // The "Generate the questions" button: hand everything to Quizbot, then show the progress page.
        try {
            [$remoteid, $names] = sender::send($job, $course, $context, $bykey);
        } catch (api_exception $e) {
            // The licence's state has changed since it was last asked (the questions ran out, the last teacher place
            // was taken): ask again, so the next page says so in full instead of offering the wizard once more.
            if (in_array($e->errorcode, licence::BLOCKING, true)) {
                licence::forget();
            }
            redirect(new moodle_url($baseurl, ['step' => 'options']), $e->getMessage(), null, $error);
        }
        $job->status = job::STATUS_RUNNING;
        $job->remoteid = $remoteid;
        $job->result = json_encode(['names' => $names]);
        $job->error = null;
        job::save($job);
        redirect(new moodle_url($baseurl, ['step' => 'run']));
    }
}

// ----------------------------------------------------------------
// The upload tab is a Moodle form of its own.
$uploadform = null;
if ($step === 'sources' && $tab === 'upload') {
    $uploadform = new upload_form(
        new moodle_url($baseurl, ['step' => 'sources', 'tab' => 'upload']),
        ['maxbytes' => (int) $course->maxbytes]
    );
    if ($data = $uploadform->get_data()) {
        file_save_draft_area_files($data->files, $context->id, 'local_quizbot', 'upload', $job->id, $uploadoptions);
        redirect(new moodle_url($baseurl, ['step' => 'sources', 'tab' => 'upload']));
    }
    $draftid = file_get_submitted_draft_itemid('files');
    file_prepare_draft_area($draftid, $context->id, 'local_quizbot', 'upload', $job->id, $uploadoptions);
    $uploadform->set_data(['cmid' => $cm->id, 'files' => $draftid]);
}

// ----------------------------------------------------------------
// A later step needs the earlier ones done.
$list = $chosen();
if ($step === 'options' && !$list) {
    redirect(
        new moodle_url($baseurl, ['step' => 'sources', 'tab' => $tab]),
        get_string('errornosources', 'local_quizbot'),
        null,
        \core\output\notification::NOTIFY_ERROR
    );
}
if ($step === 'options' && count($list) > job::MAX_SOURCES) {
    redirect(
        new moodle_url($baseurl, ['step' => 'sources', 'tab' => $tab]),
        get_string('errortoomany', 'local_quizbot', ['max' => job::MAX_SOURCES, 'n' => count($list)]),
        null,
        $error
    );
}
$total = job::total($job->optionsdata);

// ----------------------------------------------------------------
// While Quizbot works: ask how far it is.
$progress = null;
if ($step === 'run') {
    $status = null;
    $problem = '';
    try {
        $status = (new api())->status((string) $job->remoteid);
    } catch (api_exception $e) {
        $problem = $e->getMessage();
        if (
            in_array($e->errorcode, ['not_found', 'licence_missing', 'licence_invalid', 'licence_inactive', 'licence_expired',
            'site_mismatch'], true)
        ) {
            // Nothing will come of waiting: back to the step before, with the reason.
            $job->status = job::STATUS_DRAFT;
            $job->remoteid = null;
            $job->result = null;
            job::save($job);
            redirect(new moodle_url($baseurl, ['step' => 'options']), $problem, null, $error);
        }
    }
    if ($status && $status['status'] === 'done') {
        $notes = [];
        foreach ($status['sources'] as $s) {
            if ($s['state'] === 'unreadable') {
                $notes[] = ['name' => $s['name'], 'note' => $s['note']];
            }
        }
        $job->result = json_encode(['questions' => $status['questions'] ?? [], 'notes' => $notes,
            'names' => job::result($job)['names']]);
        $job->status = job::STATUS_REVIEW;
        job::save($job);
        redirect(new moodle_url($baseurl, ['step' => 'review']));
    }
    if ($status && $status['status'] === 'failed') {
        $job->status = job::STATUS_DRAFT;
        $job->remoteid = null;
        $job->result = null;
        job::save($job);
        redirect(
            new moodle_url($baseurl, ['step' => 'options']),
            (string) ($status['error']['message'] ?? get_string('errorunreachable', 'local_quizbot')),
            null,
            $error
        );
    }
    // Still working (or the service did not answer just now): the page asks again by itself every few seconds.
    $PAGE->set_periodic_refresh_delay(4);
    $rows = [];
    $stage = (string) ($status['stage'] ?? '');
    foreach ($status['sources'] ?? [] as $s) {
        $state = $s['state'] === 'read' ? 'done' : ($s['state'] === 'unreadable' ? 'problem' : ($stage === 'reading:' . $s['id']
            ? 'now' : 'waiting'));
        $rows[] = [
            'label' => get_string('progress_read', 'local_quizbot', $s['name']),
            'done' => $state === 'done',
            'now' => $state === 'now',
            'problem' => $state === 'problem',
            'waiting' => $state === 'waiting',
            'note' => $state === 'problem' ? $s['note'] : ($state === 'now' && in_array($s['kind'], ['video', 'audio'], true)
                ? get_string('progress_slow', 'local_quizbot') : ''),
        ];
    }
    $rows[] = [
        'label' => get_string('progress_write', 'local_quizbot', (int) ($status['requested'] ?? $total)),
        'done' => false,
        'now' => $stage === 'writing',
        'problem' => false,
        'waiting' => $stage !== 'writing',
        'note' => '',
    ];
    // Between two steps (and before the first one starts) nothing is "now" for a few seconds: show the step that
    // comes next as the one in hand, so that something on the page always shows that the work goes on.
    if (!array_filter($rows, fn ($row) => $row['now'])) {
        foreach ($rows as $i => $row) {
            if ($row['waiting']) {
                $rows[$i]['waiting'] = false;
                $rows[$i]['now'] = true;
                break;
            }
        }
    }
    $progress = ['rows' => $rows, 'problem' => $problem, 'quizurl' => $quizurl->out(false)];
}

// ----------------------------------------------------------------
// Can the licence be used at all?
// Asked before a run is sent (later steps need no new answer); a licence that cannot be used shows why instead of the
// wizard, so the teacher does not choose sources and questions for nothing.
$licencestate = null;
if ($job->status === job::STATUS_DRAFT && in_array($step, ['sources', 'options'], true)) {
    $licencestate = licence::status();
    if (licence::blocks($licencestate)) {
        $step = 'blocked';
    }
}

$stepnumbers = ['sources' => 1, 'options' => 2, 'run' => 3, 'review' => 3, 'edit' => 3, 'blocked' => 1];
$steps = [];
foreach (['sources' => 'step_sources', 'options' => 'step_questions', 'review' => 'step_review'] as $id => $string) {
    $steps[] = [
        'number' => $stepnumbers[$id],
        'label' => get_string($string, 'local_quizbot'),
        'done' => $stepnumbers[$id] < $stepnumbers[$step],
        'current' => $stepnumbers[$id] === $stepnumbers[$step],
    ];
}
$chosentitle = count($list) === 0 ? get_string('chosennone', 'local_quizbot')
    : (count($list) === 1 ? get_string('chosenone', 'local_quizbot') : get_string('chosen', 'local_quizbot', count($list)));

echo $OUTPUT->header();
echo page::desk_start(get_string('wizardtitle', 'local_quizbot', $quizname));

if ($licencestate && $licencestate['code'] === 'unreachable') {
    echo $OUTPUT->notification(get_string('errorunreachable', 'local_quizbot'), \core\output\notification::NOTIFY_WARNING);
}
if ($step === 'blocked') {
    $code = $licencestate['code'];
    $buyurl = (string) get_config('local_quizbot', 'buyurl');
    echo $OUTPUT->render_from_template('local_quizbot/blocked', [
        'heading' => get_string('blocked_' . $code, 'local_quizbot'),
        'text' => licence::blocked_text($licencestate),
        // The service's own words carry the exact date, the other site's address or the number of teacher places.
        'detail' => in_array($code, ['licence_expired', 'site_mismatch', 'teacher_limit'], true) ? $licencestate['message'] : '',
        'isadmin' => has_capability('moodle/site:config', context_system::instance()),
        'settingsurl' => (new moodle_url('/admin/settings.php', ['section' => 'local_quizbot']))->out(false),
        'licenceurl' => (new moodle_url('/local/quizbot/licence.php'))->out(false),
        'buyurl' => $buyurl,
        // A teacher package: whoever uses it may be the one who bought it, so the way to buy another is shown to all.
        'buymore' => $buyurl !== '' && licence::can_buy_more($licencestate),
        'quizurl' => $quizurl->out(false),
    ]);
}

if ($step === 'sources') {
    // Without script the numbers change when the tab or step changes; with it they follow every tick at once.
    $PAGE->requires->js_call_amd('local_quizbot/sources', 'init');
    $counts = [
        'course' => count(array_intersect($job->sourcesdata['keys'], array_keys($bykey))),
        'text' => trim($job->sourcesdata['text']) !== '' ? 1 : 0,
        'link' => count(array_filter($list, fn ($item) => !empty($item['link']))),
        'upload' => count(array_filter($list, fn ($item) => !empty($item['uploaded']))),
    ];
    $tabdata = [];
    foreach ($tabs as $id) {
        $tabdata[] = [
            'id' => $id,
            'label' => get_string('tab_' . $id, 'local_quizbot'),
            'active' => $id === $tab,
            // The course and upload tabs show how many items; the others only show that something is there.
            'live' => $id === 'course',
            'number' => in_array($id, ['course', 'upload'], true) ? $counts[$id] : 0,
            'tick' => in_array($id, ['link', 'text'], true) && $counts[$id] > 0,
            'url' => (new moodle_url($baseurl, ['step' => 'sources', 'tab' => $id]))->out(false),
        ];
    }
    $view = [];
    foreach ($sections as $section) {
        $items = [];
        foreach ($section['items'] as $item) {
            $items[] = [
                'key' => $item['key'],
                'kindlabel' => get_string('kind_' . $item['kind'], 'local_quizbot'),
                'name' => $item['name'],
                'detail' => $item['detail'],
                'checked' => in_array($item['key'], $job->sourcesdata['keys'], true),
            ];
        }
        $view[] = ['name' => $section['name'], 'items' => $items];
    }
    echo $OUTPUT->render_from_template('local_quizbot/sources', [
        'actionurl' => $baseurl->out(false),
        'sesskey' => sesskey(),
        'cmid' => $cm->id,
        'tab' => $tab,
        'plainform' => $tab !== 'upload',
        'tabs' => $tabdata,
        'iscourse' => $tab === 'course',
        'istext' => $tab === 'text',
        'islink' => $tab === 'link',
        'link' => $job->sourcesdata['link']['url'],
        'linkread' => array_values(array_filter($list, fn ($item) => !empty($item['link']))),
        'hassections' => (bool) $view,
        'sections' => $view,
        'pastetext' => $job->sourcesdata['text'],
        'uploadform' => $uploadform ? $uploadform->render() : '',
        'chosentitle' => $chosentitle,
        'chosen' => array_map(fn ($item) => ['name' => $item['name'], 'note' => $item['note'], 'key' => $item['key'] ?? '',
            'fixed' => empty($item['key'])], $list),
        'titlenone' => get_string('chosennone', 'local_quizbot'),
        'titleone' => get_string('chosenone', 'local_quizbot'),
        'titlemany' => get_string('chosen', 'local_quizbot', '{n}'),
        'haschosen' => (bool) $list,
        'maxsources' => job::MAX_SOURCES,
        'maxnote' => get_string('maxsources', 'local_quizbot', job::MAX_SOURCES),
        'hasvideo' => (bool) array_filter($list, fn ($item) => in_array($item['kind'], ['video', 'audio', 'youtube'], true)),
        'nexturl' => (new moodle_url($baseurl, ['step' => 'options']))->out(false),
        'cancelurl' => $quizurl->out(false),
        'steps' => $steps,
    ]);
}

$optionlists = [
    'languages' => ['language', job::languages()],
    'levels' => ['level', [
        'primary' => get_string('level_primary', 'local_quizbot'),
        'secondary' => get_string('level_secondary', 'local_quizbot'),
        'university' => get_string('level_university', 'local_quizbot'),
        'professional' => get_string('level_professional', 'local_quizbot'),
    ]],
    'difficulties' => ['difficulty', [
        'mixed' => get_string('difficulty_mixed', 'local_quizbot'),
        'easy' => get_string('difficulty_easy', 'local_quizbot'),
        'medium' => get_string('difficulty_medium', 'local_quizbot'),
        'hard' => get_string('difficulty_hard', 'local_quizbot'),
    ]],
    'keeps' => ['keep', [
        'quiz' => get_string('keep_quiz', 'local_quizbot'),
        'shared' => get_string('keep_shared', 'local_quizbot'),
    ]],
];

if ($step === 'options') {
    // With script the total follows every number typed; without it, it is checked when "Generate" is pressed.
    $PAGE->requires->js_call_amd('local_quizbot/options', 'init');
    $data = [
        'actionurl' => $baseurl->out(false),
        'sesskey' => sesskey(),
        'cmid' => $cm->id,
        'types' => [],
        'feedback' => !empty($job->optionsdata['feedback']),
        'spread' => !empty($job->optionsdata['spread']),
        'keephelp' => get_string('keephelp', 'local_quizbot', $quizname),
        'total' => $total,
        'maxquestions' => job::MAX_QUESTIONS,
        'totalover' => $total > job::MAX_QUESTIONS,
        'totalovernote' => get_string('totalover', 'local_quizbot', job::MAX_QUESTIONS),
        'sourcetext' => $chosentitle,
        'licenceleft' => $licencestate ? licence::left_text($licencestate) : '',
        'chosen' => array_map(fn ($item) => ['name' => $item['name'], 'note' => $item['note']], $list),
        'steps' => $steps,
    ];
    foreach (job::TYPES as $type) {
        $data['types'][] = [
            'id' => $type,
            'label' => get_string('type_' . $type, 'local_quizbot'),
            'help' => get_string('type_' . $type . '_help', 'local_quizbot'),
            'count' => (int) ($job->optionsdata['counts'][$type] ?? 0),
            'max' => job::MAX_PER_TYPE[$type] ?? job::MAX_QUESTIONS,
            // Said on the page only where a kind has a limit of its own.
            'limit' => isset(job::MAX_PER_TYPE[$type]) ? get_string('typemax', 'local_quizbot', job::MAX_PER_TYPE[$type]) : '',
            'over' => (int) ($job->optionsdata['counts'][$type] ?? 0) > (job::MAX_PER_TYPE[$type] ?? job::MAX_QUESTIONS),
        ];
    }
    foreach ($optionlists as $name => [$field, $values]) {
        foreach ($values as $value => $label) {
            $data[$name][] = ['value' => $value, 'label' => $label, 'selected' => ($job->optionsdata[$field] ?? '') === $value];
        }
    }
    // Bloom's levels (1.1): one row per level; the page script gets the rules and the sentences it needs.
    $skills = bloom::clean($job->optionsdata['skills'] ?? []);
    $typenames = [];
    foreach (job::TYPES as $type) {
        $typenames[$type] = get_string('type_' . $type, 'local_quizbot');
    }
    $data['bloomon'] = !empty($job->optionsdata['bloomon']);
    $data['skills'] = [];
    foreach (bloom::LEVELS as $level) {
        $kinds = array_values(array_intersect(job::TYPES, bloom::FITS[$level]));
        $data['skills'][] = [
            'id' => $level,
            'label' => bloom::name($level),
            'what' => get_string('bloomwhat_' . $level, 'local_quizbot'),
            'verbs' => get_string('bloomverbs_' . $level, 'local_quizbot'),
            'fits' => get_string('bloomfits', 'local_quizbot', implode(', ', array_map(fn ($k) => $typenames[$k], $kinds))),
            'count' => $skills[$level],
        ];
    }
    $data['bloomjson'] = json_encode([
        'fits' => bloom::FITS,
        'levels' => bloom::LEVELS,
        'types' => job::TYPES,
        'names' => array_combine(bloom::LEVELS, array_map(fn ($l) => bloom::name($l), bloom::LEVELS)),
        'typenames' => $typenames,
        'max' => job::MAX_QUESTIONS,
        'str' => [
            'sum' => get_string('bloomsum', 'local_quizbot', ['skills' => '{skills}', 'total' => '{total}']),
            'left' => get_string('bloomleft', 'local_quizbot', '{left}'),
            'over' => get_string('bloomover', 'local_quizbot', ['skills' => '{skills}', 'total' => '{total}']),
            'misfit' => get_string('bloommisfit', 'local_quizbot', ['levels' => '{levels}', 'kinds' => '{kinds}',
                'wanted' => '{wanted}', 'have' => '{have}']),
            'kind' => get_string('bloomplankind', 'local_quizbot'),
            'free' => get_string('bloomplanfree', 'local_quizbot'),
        ],
    ]);
    $PAGE->requires->js_call_amd('local_quizbot/bloom', 'init');
    echo $OUTPUT->render_from_template('local_quizbot/options', $data);
}

if ($step === 'run') {
    echo $OUTPUT->render_from_template('local_quizbot/progress', $progress + ['steps' => $steps]);
}

if ($step === 'review') {
    $view = review::view($job, $context);
    // Files from outside the course (uploaded, or behind a link) that could go into it with the questions.
    $files = [];
    if (course_files::can_add($course)) {
        foreach (course_files::candidates($context, $job->id) as $id => $file) {
            $files[] = ['id' => $id, 'name' => $file->get_filename(), 'size' => display_size($file->get_filesize())];
        }
    }
    $PAGE->requires->js_call_amd('local_quizbot/review', 'init');
    $keeps = ['quiz' => 'keep_quiz', 'shared' => 'keep_shared'];
    if ($view['haschips']) {
        $PAGE->requires->js_call_amd('local_quizbot/review', 'filter');
    }
    echo $OUTPUT->render_from_template('local_quizbot/review', $view + [
        'files' => $files,
        'hasfiles' => (bool) $files,
        'actionurl' => $baseurl->out(false),
        'sesskey' => sesskey(),
        'cmid' => $cm->id,
        'quizname' => $quizname,
        'keeptext' => get_string($keeps[$job->optionsdata['keep'] ?? 'quiz'] ?? 'keep_quiz', 'local_quizbot'),
        'steps' => $steps,
    ]);
}

if ($step === 'edit') {
    $result = job::result($job);
    $i = optional_param('qi', -1, PARAM_INT);
    $q = $editdraft ?? $result['questions'][$i];
    echo $OUTPUT->render_from_template('local_quizbot/edit', question_editor::for_template($q, $editerrors) + [
        'actionurl' => $baseurl->out(false),
        'sesskey' => sesskey(),
        'cmid' => $cm->id,
        'index' => $i,
        'number' => $i + 1,
        'typelabel' => get_string('type_' . $q['type'], 'local_quizbot'),
        'source' => $result['names'][$q['source'] ?? ''] ?? '',
        'steps' => $steps,
    ]);
}

// A problem or an idea: straight to Quizbot, with what is needed to find the run already in the e-mail.
$plugin = core_plugin_manager::instance()->get_plugin_info('local_quizbot');
$details = [
    get_string('contact_body', 'local_quizbot'),
    '',
    '',
    '----',
    'Moodle: ' . $CFG->wwwroot . ' (' . $CFG->release . ')',
    'Quizbot for Moodle: ' . ($plugin->release ?? '') . ' (' . ($plugin->versiondisk ?? '') . ')',
    'Page: ' . $step . ($job->remoteid ? ' | Quizbot run: ' . $job->remoteid : '') . ' | ' . userdate(time(), '%Y-%m-%d %H:%M %Z'),
];
$to = get_config('local_quizbot', 'supportemail') ?: 'info@quizbot.ai';
echo $OUTPUT->render_from_template('local_quizbot/contact', [
    'mailto' => 'mailto:' . $to . '?subject=' . rawurlencode(get_string('contact_subject', 'local_quizbot', $quizname))
        . '&body=' . rawurlencode(implode("\n", $details)),
]);

echo html_writer::end_div();
echo $OUTPUT->footer();
