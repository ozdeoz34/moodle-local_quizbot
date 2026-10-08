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
 * Quizbot usage, for the site administrator (1.1): the licence, teachers, courses, questions added week by week, and
 * how students did on them. Teachers' names are shown to administrators only; students are only counted.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_quizbot\local\licence;
use local_quizbot\local\page;
use local_quizbot\local\usage;

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_quizbot_usage');
$download = optional_param('download', '', PARAM_ALPHA);

$status = licence::status(false, false);
$s = $status['summary'];
$end = !empty($s['period_end']) ? strtotime($s['period_end'] . ' 00:00:00 UTC') : 0;
$since = $end ? strtotime('-1 year', $end) : time() - YEARSECS;
$u = usage::gather($since, time());

$str = fn (string $key, $param = null): string => get_string($key, 'local_quizbot', $param);
$number = fn ($n): string => format_float((float) $n, 0);
$percent = fn (?float $pct): string => $pct === null ? '–' : format_float($pct, 0) . '%';
$day = fn (int $time): string => userdate($time, get_string('strftimedateshort', 'langconfig'));

$users = $u['teachers'] ? $DB->get_records_list('user', 'id', array_keys($u['teachers']), '', 'id, '
    . implode(', ', \core_user\fields::get_name_fields())) : [];
$teachers = [];
foreach ($u['teachers'] as $userid => $t) {
    $teachers[] = ['name' => isset($users[$userid]) ? fullname($users[$userid]) : '#' . $userid,
        'courses' => count($t['courses']), 'questions' => $number($t['questions']),
        'last' => $day($t['last']) . (!empty($t['triedonly']) ? ' · ' . $str('usage_triedonly') : '')];
}
$coursenames = $u['courses'] ? $DB->get_records_list('course', 'id', array_keys($u['courses']), '', 'id, fullname') : [];
$courses = [];
foreach ($u['courses'] as $courseid => $c) {
    if (!isset($coursenames[$courseid])) {
        continue;
    }
    $courses[] = ['name' => format_string($coursenames[$courseid]->fullname),
        'url' => (new moodle_url('/local/quizbot/courseanalysis.php', ['id' => $courseid]))->out(false),
        'quizzes' => count($c['quizzes'] ?? []), 'questions' => $number($c['questions'] ?? 0),
        'students' => $number($c['students'] ?? 0), 'average' => $percent($c['average'] ?? null)];
}

if ($download === 'csv') {
    $rows = [];
    foreach ($teachers as $t) {
        $rows[] = [$str('usage_teacher'), $t['name'], $t['courses'], $t['questions'], '', '', $t['last']];
    }
    foreach ($courses as $c) {
        $rows[] = [$str('usage_course'), $c['name'], $c['quizzes'], $c['questions'], $c['students'], $c['average'], ''];
    }
    \core\dataformat::download_data('quizbot-usage', 'csv', [$str('usage_kind'), $str('usage_name'),
        $str('usage_coursesquizzes'), $str('usage_questions'), $str('course_students'), $str('analysis_average'),
        $str('usage_last')], new ArrayIterator($rows));
    die;
}

$top = max(1, max(array_column($u['weeks'], 'n')));
$weeks = array_map(fn ($w) => ['n' => $w['n'], 'label' => userdate($w['start'], get_string('strftimedateshort', 'langconfig')),
    'height' => $w['n'] ? max(4, (int) round($w['n'] / $top * 82)) : 0], $u['weeks']);

$licence = [];
if ($s) {
    $licence[] = ['label' => get_string('licence_plan', 'local_quizbot'), 'value' => (string) ($s['label'] ?? $s['plan'] ?? '')];
    if (!empty($s['teachers_limit'])) {
        $licence[] = ['label' => get_string('licence_teachers', 'local_quizbot'), 'value' => get_string(
            'licence_teachersof',
            'local_quizbot',
            ['used' => $number($s['teachers_used'] ?? 0), 'limit' => $number($s['teachers_limit'])]
        )];
    }
    if (!empty($s['media_minutes_limit'])) {
        $licence[] = ['label' => get_string('licence_media', 'local_quizbot'), 'value' => get_string(
            'licence_mediaof',
            'local_quizbot',
            ['used' => format_float(($s['media_minutes_used'] ?? 0) / 60, 1),
            'limit' => $number($s['media_minutes_limit'] / 60)]
        )];
    }
    $licence[] = ['label' => get_string('licence_questions', 'local_quizbot'), 'value' => !empty($s['unlimited'])
        ? get_string('licence_unlimited', 'local_quizbot') : get_string(
            'licence_usedof',
            'local_quizbot',
            ['used' => $number($s['used'] ?? 0), 'limit' => $number($s['limit'] ?? 0)]
        )];
    $licence[] = ['label' => get_string('licence_until', 'local_quizbot'), 'value' => licence::until($status)];
}

echo $OUTPUT->header();
echo page::desk_start($str('usage'));
echo $OUTPUT->render_from_template('local_quizbot/usage', [
    'period' => $str('usage_period', ['from' => userdate($since, get_string('strftimedate', 'langconfig')),
        'to' => userdate($end ?: time(), get_string('strftimedate', 'langconfig'))]),
    'licence' => $licence,
    'haslicence' => (bool) $licence,
    'licenceurl' => (new moodle_url('/local/quizbot/licence.php'))->out(false),
    'csvurl' => (new moodle_url('/local/quizbot/usage.php', ['download' => 'csv']))->out(false),
    'tiles' => [
        ['label' => $str('usage_active', usage::ACTIVE_DAYS), 'value' => $u['active'],
            'note' => $str('usage_teachersyear', count($u['teachers']))],
        ['label' => $str('usage_courses'), 'value' => count($u['courses']), 'note' => $str('usage_quizzes', $u['quizzes'])],
        ['label' => $str('usage_added'), 'value' => $number($u['questions']), 'note' => $str('usage_thisyear')],
        ['label' => $str('usage_students'), 'value' => $number($u['students']), 'note' => $str('usage_studentsonce')],
        ['label' => $str('usage_average'), 'value' => $percent($u['average']), 'note' => $str('usage_averagenote')],
    ],
    'weeks' => $weeks,
    'teachers' => $teachers,
    'hasteachers' => (bool) $teachers,
    'courses' => $courses,
    'hascourses' => (bool) $courses,
]);
echo html_writer::end_div();
echo $OUTPUT->footer();
