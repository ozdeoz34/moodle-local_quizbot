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
 * Quizbot for Moodle: callbacks Moodle looks for in a plugin's lib.php.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_quizbot\local\access;

/**
 * Adds "Generate with Quizbot" and "Quizbot Analysis" to the navigation of every quiz the user may use them on, and
 * "My progress" for its students when the course has questions Quizbot wrote (the quiz's line of tabs; amd/src/navtab.js
 * takes them out of "More"). No script needed to reach them, so they are there under every theme.
 *
 * @param settings_navigation $nav the settings navigation of the current page
 * @param context $context the context of the current page
 */
function local_quizbot_extend_settings_navigation(settings_navigation $nav, context $context): void {
    global $DB, $PAGE;

    if (!$PAGE->cm || $PAGE->cm->modname !== 'quiz') {
        return;
    }
    $modulenode = $nav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$modulenode) {
        return;
    }
    $analyse = access::can_analyse($context);
    $courseid = (int) $PAGE->cm->course;
    // A student's own progress; a teacher has the analysis instead.
    $ownprogress = !$analyse && has_capability('local/quizbot:viewownprogress', context_course::instance($courseid))
        && $DB->record_exists('local_quizbot_question', ['courseid' => $courseid]);
    $items = [
        'local_quizbot_generate' => [access::can_generate($context), 'generate',
            new moodle_url('/local/quizbot/generate.php', ['cmid' => $PAGE->cm->id])],
        'local_quizbot_analysis' => [$analyse, 'analysis',
            new moodle_url('/local/quizbot/analysis.php', ['cmid' => $PAGE->cm->id])],
        'local_quizbot_myprogress' => [$ownprogress, 'myprogress',
            new moodle_url('/local/quizbot/myprogress.php', ['id' => $courseid])],
    ];
    foreach ($items as $key => [$allowed, $string, $url]) {
        if ($allowed) {
            $modulenode->add_node(navigation_node::create(
                get_string($string, 'local_quizbot'),
                $url,
                navigation_node::TYPE_SETTING,
                null,
                $key
            ));
        }
    }
}

/**
 * Adds "My progress" (students, the course's "More" menu) and "Quizbot Analysis: the whole course" (teachers, the
 * course's Reports) to a course's navigation, once the course has questions Quizbot wrote (1.1).
 *
 * @param navigation_node $navigation the course's navigation
 * @param stdClass $course
 * @param context $context the course's context
 */
function local_quizbot_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context): void {
    global $DB;

    if (!$DB->record_exists('local_quizbot_question', ['courseid' => $course->id])) {
        return;
    }
    if (has_capability('local/quizbot:viewownprogress', $context)) {
        $navigation->add(
            get_string('myprogress', 'local_quizbot'),
            new moodle_url('/local/quizbot/myprogress.php', ['id' => $course->id]),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_quizbot_myprogress',
            new pix_icon('i/report', '')
        );
    }
    // Teachers: the whole course's analysis, among the course's reports (or in its menu where there is no Reports).
    if (has_capability('local/quizbot:viewanalysis', $context)) {
        $reports = $navigation->find('coursereports', navigation_node::TYPE_CONTAINER) ?: $navigation;
        $reports->add(
            get_string('courseanalysis', 'local_quizbot'),
            new moodle_url('/local/quizbot/courseanalysis.php', ['id' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_quizbot_courseanalysis',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Loads the button script on a quiz's pages and adds the result card to a quiz attempt's review page, in Moodle 4.1 to
 * 4.3, which have no hook for it. From 4.4 on Moodle calls the hook listed in db/hooks.php instead and leaves this
 * function alone.
 *
 * @return string HTML for the foot of the page
 */
function local_quizbot_before_footer(): string {
    \local_quizbot\hook_callbacks::load_button();
    return \local_quizbot\hook_callbacks::student_card();
}
