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

namespace local_quizbot\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Turns the questions the teacher accepted into ordinary Moodle questions and puts them into the quiz.
 *
 * The questions are written as Moodle XML and taken in by Moodle's own importer - the same road as a teacher's
 * "Import" - so every question type is created by Moodle's own code, with Moodle's own defaults. Then each one is
 * added to the end of the quiz.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_saver {
    /** @var string[] the kinds of question that take a hint (Moodle's true/false and essay questions have none) */
    public const HINTED = ['multichoice', 'shortanswer', 'numerical', 'match'];

    /**
     * Saves the questions and adds them to the quiz.
     *
     * @param array $questions as returned by the Quizbot service
     * @param \stdClass $course
     * @param \cm_info|\stdClass $cm the quiz's course module
     * @param string $keep 'quiz' = the quiz's own question bank, 'shared' = the course's shared question bank
     * @param int $jobid the wizard run they came from
     * @param array $names source id => the source's name as the teacher saw it
     * @return int how many questions were added to the quiz
     */
    public static function save(array $questions, \stdClass $course, $cm, string $keep, int $jobid = 0, array $names = []): int {
        global $DB;

        if (!$questions) {
            return 0;
        }
        $quizcontext = \context_module::instance($cm->id);
        if ($keep === 'shared' && class_exists(\core_question\local\bank\question_bank_helper::class)) {
            // Moodle 5.0 and later: the course's question bank is an activity of its own.
            $bank = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type($course, true);
            $bankcontext = \context_module::instance($bank->id);
        } else if ($keep === 'shared') {
            // Before Moodle 5.0 the course itself holds its question bank.
            $bankcontext = \context_course::instance($course->id);
        } else {
            $bankcontext = $quizcontext;
        }
        require_capability('moodle/question:add', $bankcontext);
        $category = question_get_default_category($bankcontext->id, true);
        if (!$category) {
            // Before Moodle 5.0 the call above does not create the first category of a bank that has none yet.
            $category = question_make_default_categories([$bankcontext]);
        }

        $file = make_request_directory() . '/quizbot.xml';
        file_put_contents($file, self::to_xml($questions));

        $importer = new \qformat_xml();
        $importer->setCategory($category);
        $importer->setContexts([$bankcontext]);
        $importer->setCourse($course);
        $importer->setFilename($file);
        $importer->setRealfilename('quizbot.xml');
        $importer->setMatchgrades('error');
        $importer->setCatfromfile(false);
        $importer->setContextfromfile(false);
        $importer->setStoponerror(true);

        // The importer prints its own progress; that belongs on Moodle's import page, not on ours.
        ob_start();
        $ok = $importer->importpreprocess() && $importer->importprocess() && $importer->importpostprocess();
        $printed = ob_get_clean();
        if (!$ok || !$importer->questionids) {
            debugging('Quizbot: Moodle refused the questions: ' . strip_tags((string) $printed), DEBUG_DEVELOPER);
            throw new \moodle_exception('errorsaving', 'local_quizbot');
        }

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $quiz->cmid = $cm->id;
        $added = 0;
        foreach ($importer->questionids as $questionid) {
            if (quiz_add_quiz_question($questionid, $quiz) !== false) {
                $added++;
            }
        }
        if (class_exists(\mod_quiz\quiz_settings::class)) {
            \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        } else {
            quiz_update_sumgrades($quiz);       // Moodle 4.1: the same sum, before the quiz got its grade calculator.
        }
        // The importer takes the questions in the order of the XML, which leaves out the incomplete ones.
        $written = array_values(array_filter($questions, fn ($q) => self::body($q) !== null));
        self::remember($written, array_values($importer->questionids), $course, $cm, $jobid, $names);
        return $added;
    }

    /**
     * Keeps what Quizbot found out about each added question - its topic, Bloom's level and difficulty - for Quizbot
     * Analysis. The questions are already in the quiz: a failure here is reported to developers, not to the teacher.
     *
     * @param array $questions the questions written to the XML, in its order
     * @param int[] $questionids the Moodle questions they became, in the same order
     * @param \stdClass $course
     * @param \cm_info|\stdClass $cm the quiz's course module
     * @param int $jobid
     * @param array $names source id => name
     */
    private static function remember(array $questions, array $questionids, \stdClass $course, $cm, int $jobid, array $names): void {
        global $DB, $USER;

        if (count($questions) !== count($questionids)) {
            debugging('Quizbot: ' . count($questionids) . ' questions saved for ' . count($questions) . ' written; their labels '
                . 'are not kept.', DEBUG_DEVELOPER);
            return;
        }
        try {
            $entries = $DB->get_records_list(
                'question_versions',
                'questionid',
                $questionids,
                '',
                'questionid, questionbankentryid'
            );
            $rows = [];
            $sourcecmids = [];
            foreach ($questionids as $i => $questionid) {
                if (!isset($entries[$questionid])) {
                    continue;
                }
                $q = $questions[$i];
                $source = (string) ($q['source'] ?? '');
                $sourcecmids[$source] = $sourcecmids[$source] ?? source_finder::cmid_of($source);
                $rows[] = (object) [
                    'questionbankentryid' => $entries[$questionid]->questionbankentryid,
                    'questionid' => $questionid,
                    'courseid' => $course->id,
                    'cmid' => $cm->id,
                    'jobid' => $jobid,
                    'userid' => $USER->id,
                    'qtype' => $q['type'],
                    'sourcename' => \core_text::substr((string) ($names[$source] ?? ''), 0, 255),
                    'sourcecmid' => $sourcecmids[$source],
                    'topic' => \core_text::substr(trim((string) ($q['topic'] ?? '')), 0, 80),
                    'bloom' => in_array($q['bloom'] ?? '', bloom::LEVELS, true) ? $q['bloom'] : '',
                    'difficulty' => in_array($q['difficulty'] ?? '', ['easy', 'medium', 'hard'], true) ? $q['difficulty'] : '',
                    'edited' => empty($q['edited']) ? 0 : 1,
                    'rewritten' => empty($q['rewritten']) ? 0 : 1,
                    'timecreated' => time(),
                ];
            }
            $DB->insert_records('local_quizbot_question', $rows);
        } catch (\dml_exception $e) {
            debugging('Quizbot: could not keep the questions\' labels: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * The questions as one Moodle XML document.
     *
     * @param array $questions
     * @return string
     */
    public static function to_xml(array $questions): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<quiz>\n";
        foreach ($questions as $q) {
            $body = self::body($q);
            if ($body === null) {
                continue;
            }
            $type = $q['type'] === 'match' ? 'matching' : $q['type'];
            $xml .= '<question type="' . $type . '">' . "\n"
                . '<name><text>' . self::plain(shorten_text(self::readable((string) $q['text']), 90, true, '…'))
                    . "</text></name>\n"
                . '<questiontext format="html">' . self::html((string) $q['text']) . "</questiontext>\n"
                . '<generalfeedback format="html">' . self::html((string) ($q['feedback'] ?? '')) . "</generalfeedback>\n"
                . "<defaultgrade>1</defaultgrade>\n<penalty>0.3333333</penalty>\n<hidden>0</hidden>\n"
                . $body
                // A hint gives a second try in a quiz that gives feedback after each answer (remedial quizzes);
                // true/false and essay questions take none.
                . (trim((string) ($q['hint'] ?? '')) !== '' && in_array($q['type'], self::HINTED, true)
                    ? '<hint format="html">' . self::html((string) $q['hint']) . "</hint>\n" : '')
                . "<tags><tag><text>Quizbot</text></tag></tags>\n"
                . "</question>\n";
        }
        return $xml . "</quiz>\n";
    }

    /**
     * The part of a question's XML that differs by type; null for a question that is not complete.
     *
     * @param array $q
     * @return string|null
     */
    private static function body(array $q): ?string {
        $combined = '<correctfeedback format="html"><text></text></correctfeedback>' . "\n"
            . '<partiallycorrectfeedback format="html"><text></text></partiallycorrectfeedback>' . "\n"
            . '<incorrectfeedback format="html"><text></text></incorrectfeedback>' . "\n";
        $answer = fn (string $text, int $fraction, string $feedback = '', string $extra = '', bool $ashtml = false) =>
            '<answer fraction="' . $fraction . '" format="' . ($ashtml ? 'html' : 'moodle_auto_format') . '">'
            . ($ashtml ? self::html($text) : '<text>' . self::plain($text) . '</text>')
            . '<feedback format="html">' . self::html($feedback) . '</feedback>' . $extra . "</answer>\n";

        switch ($q['type']) {
            case 'multichoice':
                $options = $q['options'] ?? [];
                if (count($options) < 2 || count(array_filter($options, fn ($o) => !empty($o['correct']))) !== 1) {
                    return null;
                }
                $out = "<single>true</single>\n<shuffleanswers>true</shuffleanswers>\n<answernumbering>abc</answernumbering>\n"
                    . "<showstandardinstruction>0</showstandardinstruction>\n" . $combined;
                foreach ($options as $o) {
                    $out .= $answer(
                        (string) $o['text'],
                        !empty($o['correct']) ? 100 : 0,
                        (string) ($o['feedback'] ?? ''),
                        '',
                        true
                    );
                }
                return $out;

            case 'truefalse':
                if (!is_bool($q['answer'] ?? null)) {
                    return null;
                }
                return $answer('true', $q['answer'] ? 100 : 0) . $answer('false', $q['answer'] ? 0 : 100);

            case 'shortanswer':
                $answers = array_filter(array_map('strval', $q['answers'] ?? []), fn ($a) => trim($a) !== '');
                if (!$answers) {
                    return null;
                }
                $out = "<usecase>0</usecase>\n";
                foreach ($answers as $a) {
                    $out .= $answer($a, 100);
                }
                return $out;

            case 'numerical':
                if (!is_numeric($q['answer'] ?? null)) {
                    return null;
                }
                $tolerance = is_numeric($q['tolerance'] ?? null) ? abs((float) $q['tolerance']) : 0;
                return $answer((string) (0 + $q['answer']), 100, '', '<tolerance>' . $tolerance . '</tolerance>')
                    . "<unitgradingtype>0</unitgradingtype>\n<unitpenalty>0.1</unitpenalty>\n"
                    . "<showunits>3</showunits>\n<unitsleft>0</unitsleft>\n";

            case 'match':
                $pairs = $q['pairs'] ?? [];
                if (count($pairs) < 3) {
                    return null;
                }
                $out = "<shuffleanswers>true</shuffleanswers>\n" . $combined;
                foreach ($pairs as $p) {
                    $out .= '<subquestion format="html">' . self::html((string) $p['left'])
                        . '<answer><text>' . self::plain((string) $p['right']) . "</text></answer></subquestion>\n";
                }
                return $out;

            case 'essay':
                return "<responseformat>editor</responseformat>\n<responserequired>1</responserequired>\n"
                    . "<responsefieldlines>10</responsefieldlines>\n"
                    . "<attachments>0</attachments>\n<attachmentsrequired>0</attachmentsrequired>\n"
                    . '<graderinfo format="html">' . self::html((string) ($q['graderinfo'] ?? '')) . "</graderinfo>\n"
                    . '<responsetemplate format="html"><text></text></responsetemplate>' . "\n";
        }
        return null;
    }

    /**
     * A question's text made readable without the maths filter - for its name, which Moodle shows as plain text (in
     * the quiz's question list, the question bank, reports). "\(\int x e^{x}\,dx\)" becomes "∫ x eˣ dx".
     *
     * @param string $text
     * @return string
     */
    public static function readable(string $text): string {
        $sup = ['0' => '⁰', '1' => '¹', '2' => '²', '3' => '³', '4' => '⁴', '5' => '⁵', '6' => '⁶', '7' => '⁷', '8' => '⁸',
            '9' => '⁹', '+' => '⁺', '-' => '⁻', 'n' => 'ⁿ', 'x' => 'ˣ', 'i' => 'ⁱ'];
        $symbols = ['\int' => '∫', '\sum' => '∑', '\prod' => '∏', '\infty' => '∞', '\pi' => 'π', '\theta' => 'θ', '\alpha' => 'α',
            '\beta' => 'β', '\gamma' => 'γ', '\delta' => 'δ', '\Delta' => 'Δ', '\lambda' => 'λ', '\mu' => 'μ', '\sigma' => 'σ',
            '\omega' => 'ω', '\Omega' => 'Ω', '\phi' => 'φ', '\rho' => 'ρ', '\times' => '×', '\cdot' => '·', '\div' => '÷',
            '\pm' => '±', '\leq' => '≤', '\le' => '≤', '\geq' => '≥', '\ge' => '≥', '\neq' => '≠', '\ne' => '≠', '\approx' => '≈',
            '\lt' => '<', '\gt' => '>', '\to' => '→', '\rightarrow' => '→', '\degree' => '°', '\circ' => '°', '\partial' => '∂',
            '\sqrt' => '√', '\ln' => 'ln', '\log' => 'log', '\sin' => 'sin', '\cos' => 'cos', '\tan' => 'tan', '\lim' => 'lim',
            '\,' => ' ', '\;' => ' ', '\!' => '', '\quad' => ' ', '\left' => '', '\right' => ''];
        $maths = '/\\\\\((.*?)\\\\\)|\\\\\[(.*?)\\\\\]/su';
        return trim(preg_replace('/\s+/u', ' ', preg_replace_callback($maths, function ($m) use ($sup, $symbols) {
            $tex = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            $tex = str_replace(['^{\circ}', '^\circ'], '°', $tex);
            // Words inside a formula, as \text{ m/s}: the words themselves.
            $words = 'text|mathrm|textrm|textbf|mathbf|textit|mathit|operatorname';
            $tex = preg_replace('/\\\\(?:' . $words . ')\s*\{([^{}]*)\}/u', '$1', $tex);
            // A fraction as a/b; brackets only round a part that is more than one simple term.
            $tex = preg_replace_callback('/\\\\[dt]?frac\{([^{}]*)\}\{([^{}]*)\}/u', function ($f) {
                $part = fn ($p) => preg_match('/^[\p{L}\p{N}.]+$/u', trim($p)) ? trim($p) : '(' . trim($p) . ')';
                return $part($f[1]) . '/' . $part($f[2]);
            }, $tex);
            $tex = preg_replace_callback('/\^\{?([0-9+\-nxi]+)\}?/u', function ($p) use ($sup) {
                $out = '';
                foreach (preg_split('//u', $p[1], -1, PREG_SPLIT_NO_EMPTY) as $c) {
                    $out .= $sup[$c] ?? $c;
                }
                return $out;
            }, $tex);
            $sub = ['0' => '₀', '1' => '₁', '2' => '₂', '3' => '₃', '4' => '₄', '5' => '₅', '6' => '₆', '7' => '₇', '8' => '₈',
                '9' => '₉', '+' => '₊', '-' => '₋', 'n' => 'ₙ', 'x' => 'ₓ', 'i' => 'ᵢ'];
            $tex = preg_replace_callback('/_\{?([0-9+\-nxi]+)\}?/u', function ($p) use ($sub) {
                $out = '';
                foreach (preg_split('//u', $p[1], -1, PREG_SPLIT_NO_EMPTY) as $c) {
                    $out .= $sub[$c] ?? $c;
                }
                return $out;
            }, $tex);
            uksort($symbols, fn ($a, $b) => strlen($b) - strlen($a));
            $tex = str_replace(array_keys($symbols), array_values($symbols), $tex);
            return str_replace(['{', '}', '\\'], '', $tex);
        }, $text)));
    }

    /**
     * Plain text as the inside of an XML element.
     *
     * @param string $text
     * @return string
     */
    private static function plain(string $text): string {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Plain text as a paragraph of HTML, inside a text element. The service sends text only; it is escaped twice on
     * purpose - once to become HTML, once to sit inside XML - so nothing in it can act as markup.
     *
     * @param string $text
     * @return string
     */
    private static function html(string $text): string {
        if (trim($text) === '') {
            return '<text></text>';
        }
        return '<text>' . self::plain('<p>' . s($text) . '</p>') . '</text>';
    }
}
