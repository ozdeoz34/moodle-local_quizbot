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

/**
 * Quizbot on a quiz's pages: "Generate with Quizbot" beside Moodle's "Add question", and "Quizbot Analysis" on the
 * quiz's line of tabs.
 *
 * Moodle gives no place for a plugin's button there (the row is written in mod_quiz's renderer), and keeps every
 * plugin's tab under "More", so small scripts do it. This callback only decides WHETHER to load them. The entries in
 * the quiz's navigation (lib.php) need no script and are always there.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Loads the scripts on a quiz's pages, for users who may use Quizbot on that quiz or see its analysis.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(\core\hook\output\before_footer_html_generation $hook): void {
        self::load_button();
        $hook->add_html(self::student_card());
    }

    /**
     * The result card on the review page of a finished attempt (1.1), for the student whose attempt it is and their
     * teachers - when the quiz lets the student see the marks, and the attempt has questions Quizbot wrote. The HTML
     * goes at the foot of the page; a small script moves it to the top.
     *
     * @return string HTML, or '' when there is no card
     */
    public static function student_card(): string {
        global $PAGE, $DB, $USER, $OUTPUT;

        if ($PAGE->pagetype !== 'mod-quiz-review' || !$PAGE->cm || $PAGE->cm->modname !== 'quiz') {
            return '';
        }
        $attempt = $DB->get_record('quiz_attempts', ['id' => optional_param('attempt', 0, PARAM_INT)]);
        if (!$attempt || (int) $attempt->quiz !== (int) $PAGE->cm->instance) {
            return '';
        }
        $own = (int) $attempt->userid === (int) $USER->id;
        if ($own ? !has_capability('local/quizbot:viewownprogress', $PAGE->context) : !local\access::can_analyse($PAGE->context)) {
            return '';
        }
        $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
        $card = local\student::gather_card($attempt, $quiz, $PAGE->cm);
        if (!$card) {
            return '';
        }
        $course = $PAGE->course;
        $words = ['strong' => 'card_strong', 'getting' => 'card_getting', 'revise' => 'card_revisetopic'];
        $data = [
            'progressurl' => (new \moodle_url('/local/quizbot/myprogress.php', ['id' => $course->id]
                + ($own ? [] : ['userid' => $attempt->userid])))->out(false),
            'topics' => array_map(fn ($t) => [
                'name' => $t['name'],
                'righttext' => get_string('card_right', 'local_quizbot', ['right' => $t['right'], 'total' => $t['total']]),
                'word' => get_string($words[$t['word']], 'local_quizbot'),
                'wordclass' => 'is-' . $t['word'],
                'width' => max(2, (int) round($t['pct'])),
                'weak' => $t['word'] === 'revise',
            ], $card['topics']),
            'hasrevise' => (bool) $card['revise'],
            'allstrong' => !$card['revise'],
            'pendingnote' => $card['pending'] ? get_string('card_pending', 'local_quizbot', $card['pending']) : '',
        ];
        if ($card['revise']) {
            $r = $card['revise'];
            $url = local\student::material_url($course, (int) $attempt->userid, $r['sourcecmid'], $r['source']);
            $data += [
                'revisename' => $r['name'],
                'revisetext' => get_string('card_revisetext', 'local_quizbot', ['right' => $r['right'], 'total' => $r['total']]),
                'materialurl' => $url ? $url->out(false) : '',
                'materialname' => $r['source'],
            ];
        }
        $PAGE->requires->js_call_amd('local_quizbot/card', 'init');
        return $OUTPUT->render_from_template('local_quizbot/studentcard', $data);
    }

    /**
     * The work of the hook above. Moodle 4.1 to 4.3 have no such hook and come here from the older callback
     * local_quizbot_before_footer() in lib.php.
     */
    public static function load_button(): void {
        global $PAGE, $OUTPUT;

        // On a course's pages: "My progress" (and on a quiz "Quizbot Analysis") on the line of tabs, not under "More".
        if ($PAGE->course && (int) $PAGE->course->id !== SITEID) {
            $PAGE->requires->js_call_amd('local_quizbot/navtab', 'init');
        }
        if (!$PAGE->cm || $PAGE->cm->modname !== 'quiz') {
            return;
        }
        // The quiz's main page and its Questions page: the two places a teacher adds questions from.
        $pages = ['mod-quiz-view' => 'view', 'mod-quiz-edit' => 'edit'];
        if (!isset($pages[$PAGE->pagetype]) || !local\access::can_generate($PAGE->context)) {
            return;
        }
        $url = new \moodle_url('/local/quizbot/generate.php', ['cmid' => $PAGE->cm->id]);
        $PAGE->requires->js_call_amd('local_quizbot/quizbutton', 'init', [[
            'url' => $url->out(false),
            'label' => get_string('generate', 'local_quizbot'),
            'page' => $pages[$PAGE->pagetype],
            'icon' => $OUTPUT->image_url('quizbothead', 'local_quizbot')->out(false),
        ]]);
        // The Questions page of a quiz with Quizbot questions: say so when the marks do not add up to the maximum grade.
        if ($PAGE->pagetype === 'mod-quiz-edit' && self::has_quizbot_questions((int) $PAGE->cm->id)) {
            $PAGE->requires->js_call_amd('local_quizbot/marks', 'init', [[
                'note' => get_string('marks_note', 'local_quizbot'),
                'button' => get_string('marks_button', 'local_quizbot'),
            ]]);
        }
    }

    /**
     * Whether Quizbot added questions to this quiz.
     *
     * @param int $cmid
     * @return bool
     */
    private static function has_quizbot_questions(int $cmid): bool {
        global $DB;
        return $DB->record_exists('local_quizbot_question', ['cmid' => $cmid]);
    }
}
