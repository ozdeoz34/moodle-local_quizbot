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
 * The site's Quizbot licence, for the site administrator: is it working, how much is left, until when.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_quizbot\local\licence;
use local_quizbot\local\page;

require('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_quizbot_licence');
$fresh = optional_param('check', 0, PARAM_BOOL) && confirm_sesskey();
// The licence itself, not "may I write questions": an administrator need not hold a teacher place to look at it.
$status = licence::status($fresh, false);
$s = $status['summary'];
$number = fn ($n) => format_float((float) $n, 0);

echo $OUTPUT->header();
echo page::desk_start(get_string('licencestatus', 'local_quizbot'));

if ($status['ok']) {
    echo $OUTPUT->notification(get_string('licence_ok', 'local_quizbot'), \core\output\notification::NOTIFY_SUCCESS);
} else if ($status['code'] === 'unreachable' || $status['code'] === 'busy') {
    echo $OUTPUT->notification(get_string('errorunreachable', 'local_quizbot'), \core\output\notification::NOTIFY_WARNING);
} else {
    $text = get_string('blocked_' . $status['code'], 'local_quizbot') . '. ' . licence::blocked_text($status);
    if (in_array($status['code'], ['licence_expired', 'site_mismatch'], true)) {
        $text .= ' ' . $status['message'];
    }
    echo $OUTPUT->notification($text, \core\output\notification::NOTIFY_ERROR);
}

if ($s) {
    $table = new html_table();
    // Moodle 5 draws every cell of a plain table boxed unless it carries "table-reboot"; the Quizbot style has hairlines.
    $table->attributes['class'] = 'generaltable table-reboot';
    $table->data = [[get_string('licence_plan', 'local_quizbot'), s($s['label'] ?? $s['plan'] ?? '')]];
    if (!empty($s['unlimited'])) {
        // A school plan: questions are not counted for the school.
        $table->data[] = [get_string('licence_questions', 'local_quizbot'), get_string('licence_unlimited', 'local_quizbot')];
        $table->data[] = [get_string('licence_used', 'local_quizbot'), $number($s['used'] ?? 0)];
    } else {
        $table->data[] = [get_string('licence_used', 'local_quizbot'), get_string('licence_usedof', 'local_quizbot', [
            'used' => $number($s['used'] ?? 0), 'limit' => $number($s['limit'] ?? 0)])];
        $table->data[] = [get_string('licence_left', 'local_quizbot'), $number($s['remaining'] ?? 0)];
    }
    if (!empty($s['teachers_limit'])) {
        $table->data[] = [get_string('licence_teachers', 'local_quizbot'), get_string('licence_teachersof', 'local_quizbot', [
            'used' => $number($s['teachers_used'] ?? 0), 'limit' => $number($s['teachers_limit'])])];
    }
    if (!empty($s['media_minutes_limit'])) {
        $table->data[] = [get_string('licence_media', 'local_quizbot'), get_string('licence_mediaof', 'local_quizbot', [
            'used' => format_float(($s['media_minutes_used'] ?? 0) / 60, 1), 'limit' => $number($s['media_minutes_limit'] / 60)])];
    }
    $table->data[] = [get_string('licence_until', 'local_quizbot'), licence::until($status)];
    $table->data[] = [get_string('licence_site', 'local_quizbot'),
        s($s['site'] ?? '') ?: get_string('licence_sitenone', 'local_quizbot')];
    echo html_writer::table($table);
    echo html_writer::tag('p', get_string('licence_counted', 'local_quizbot'), ['class' => 'small text-muted']);
    if (!empty($s['topup'])) {
        $buyurl = (string) get_config('local_quizbot', 'buyurl');
        echo html_writer::tag('p', get_string('licence_topup', 'local_quizbot') . ($buyurl !== '' ? ' ' . html_writer::link(
            $buyurl,
            get_string('blocked_buymore', 'local_quizbot'),
            ['target' => '_blank', 'rel' => 'noopener']
        ) : ''), ['class' => 'small text-muted']);
    }
}

echo $OUTPUT->single_button(
    new moodle_url('/local/quizbot/licence.php', ['check' => 1, 'sesskey' => sesskey()]),
    get_string('licence_check', 'local_quizbot'),
    'post'
);
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/admin/settings.php', ['section' => 'local_quizbot']),
    get_string('blocked_settings', 'local_quizbot')
), ['class' => 'mt-3']);
echo html_writer::end_div();
echo $OUTPUT->footer();
