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

/**
 * Editing one question on the review page, before it is in the quiz: the form's values in, the edited question out,
 * and what the edit page shows. The question keeps the shape the Quizbot service returned, so question_saver saves an
 * edited question exactly like one that was not touched.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_editor {
    /** @var int a multiple-choice question always has this many options (as Quizbot writes them) */
    public const OPTIONS = 4;

    /** @var int the most accepted answers of a short-answer question */
    public const ANSWERS = 4;

    /** @var int the fewest and the most pairs of a matching question */
    public const MIN_PAIRS = 3;

    /** @var int the most pairs of a matching question */
    public const MAX_PAIRS = 6;

    /**
     * Reads the edit form.
     *
     * @param array $original the question as it was before this edit
     * @return array [the edited question, the problems found - field => message; empty when it can be saved]
     */
    public static function from_form(array $original): array {
        $text = fn (string $name, int $max = 1000) => \core_text::substr(trim(optional_param($name, '', PARAM_TEXT)), 0, $max);
        $list = function (string $name, int $max = 300): array {
            $values = optional_param_array($name, [], PARAM_TEXT);
            return array_map(fn ($v) => \core_text::substr(trim((string) $v), 0, $max), $values);
        };
        $q = $original;
        $errors = [];
        $q['text'] = $text('text', 2000);
        if ($q['text'] === '') {
            $errors['text'] = get_string('edit_err_text', 'local_quizbot');
        }
        if ($q['type'] !== 'multichoice') {
            $q['feedback'] = $text('feedback', 1000);
        }
        // The Bloom's level (1.1): one this kind can test, or none. A level Quizbot gave is kept even where the kind
        // does not usually test it.
        $skill = optional_param('bloom', (string) ($original['bloom'] ?? ''), PARAM_ALPHA);
        $fits = $skill === '' || $skill === ($original['bloom'] ?? '') || in_array($q['type'], bloom::FITS[$skill] ?? [], true);
        $q['bloom'] = $fits ? $skill : (string) ($original['bloom'] ?? '');
        // The level Quizbot wrote it at stays known: the review page compares that, not the teacher's change, with
        // what was asked for.
        $q['bloomwritten'] = (string) ($original['bloomwritten'] ?? ($original['bloom'] ?? ''));

        switch ($q['type']) {
            case 'multichoice':
                $texts = $list('opt');
                $notes = $list('optfb');
                $correct = optional_param('correct', -1, PARAM_INT);
                $q['options'] = [];
                for ($i = 0; $i < self::OPTIONS; $i++) {
                    $q['options'][] = ['text' => $texts[$i] ?? '', 'correct' => $i === $correct, 'feedback' => $notes[$i] ?? ''];
                }
                $filled = array_filter(array_column($q['options'], 'text'), fn ($t) => $t !== '');
                if (count($filled) < self::OPTIONS) {
                    $errors['options'] = get_string('edit_err_options', 'local_quizbot', self::OPTIONS);
                } else if (count(array_unique(array_map('core_text::strtolower', $filled))) < self::OPTIONS) {
                    $errors['options'] = get_string('edit_err_same', 'local_quizbot');
                } else if ($correct < 0 || $correct >= self::OPTIONS) {
                    $errors['options'] = get_string('edit_err_correct', 'local_quizbot');
                }
                break;

            case 'truefalse':
                $answer = optional_param('answer', '', PARAM_ALPHA);
                if (!in_array($answer, ['true', 'false'], true)) {
                    $errors['answer'] = get_string('edit_err_truefalse', 'local_quizbot');
                } else {
                    $q['answer'] = $answer === 'true';
                }
                break;

            case 'shortanswer':
                $q['answers'] = array_values(array_unique(array_filter(
                    array_slice($list('ans', 80), 0, self::ANSWERS),
                    fn ($a) => $a !== ''
                )));
                if (!$q['answers']) {
                    $errors['answers'] = get_string('edit_err_answers', 'local_quizbot');
                }
                break;

            case 'numerical':
                $answer = unformat_float(optional_param('answer', '', PARAM_RAW_TRIMMED), true);
                $tolerance = unformat_float(optional_param('tolerance', '0', PARAM_RAW_TRIMMED) ?: '0', true);
                if ($answer === false || $answer === null) {
                    $errors['answer'] = get_string('edit_err_number', 'local_quizbot');
                } else {
                    $q['answer'] = $answer;
                }
                if ($tolerance === false || $tolerance === null || $tolerance < 0) {
                    $errors['tolerance'] = get_string('edit_err_tolerance', 'local_quizbot');
                } else {
                    $q['tolerance'] = $tolerance;
                }
                break;

            case 'match':
                $lefts = $list('left');
                $rights = $list('right', 120);
                $q['pairs'] = [];
                $half = false;
                for ($i = 0; $i < self::MAX_PAIRS; $i++) {
                    $left = $lefts[$i] ?? '';
                    $right = $rights[$i] ?? '';
                    if ($left !== '' && $right !== '') {
                        $q['pairs'][] = ['left' => $left, 'right' => $right];
                    } else if ($left !== '' || $right !== '') {
                        $half = true;
                    }
                }
                if ($half) {
                    $errors['pairs'] = get_string('edit_err_halfpair', 'local_quizbot');
                } else if (count($q['pairs']) < self::MIN_PAIRS) {
                    $errors['pairs'] = get_string('edit_err_pairs', 'local_quizbot', self::MIN_PAIRS);
                } else if (
                    count(array_unique(array_map('core_text::strtolower', array_column(
                        $q['pairs'],
                        'left'
                    )))) < count($q['pairs'])
                ) {
                    $errors['pairs'] = get_string('edit_err_sameleft', 'local_quizbot');
                }
                break;

            case 'essay':
                $q['graderinfo'] = $text('graderinfo', 3000);
                break;
        }
        return [$q, $errors];
    }

    /**
     * What the edit page shows for a question.
     *
     * @param array $q the question (as saved, or as just typed when it could not be saved)
     * @param array $errors field => message
     * @return array template context
     */
    public static function for_template(array $q, array $errors): array {
        $data = [
            'text' => $q['text'] ?? '',
            'feedback' => $q['feedback'] ?? '',
            'is' . $q['type'] => true,
            'errors' => array_values($errors),
            'haserrors' => (bool) $errors,
        ];
        foreach ($errors as $field => $message) {
            $data['err_' . $field] = $message;
        }
        $current = (string) ($q['bloom'] ?? '');
        $data['skills'] = [];
        foreach (bloom::LEVELS as $level) {
            if (in_array($q['type'], bloom::FITS[$level], true) || $level === $current) {
                $data['skills'][] = ['value' => $level, 'label' => bloom::name($level), 'selected' => $level === $current];
            }
        }
        switch ($q['type']) {
            case 'multichoice':
                $data['options'] = [];
                for ($i = 0; $i < self::OPTIONS; $i++) {
                    $o = $q['options'][$i] ?? [];
                    $data['options'][] = ['i' => $i, 'n' => $i + 1, 'text' => $o['text'] ?? '', 'note' => $o['feedback'] ?? '',
                        'correct' => !empty($o['correct'])];
                }
                break;
            case 'truefalse':
                $data['istrue'] = ($q['answer'] ?? null) === true;
                $data['isfalse'] = ($q['answer'] ?? null) === false;
                break;
            case 'shortanswer':
                $data['answers'] = [];
                for ($i = 0; $i < self::ANSWERS; $i++) {
                    $data['answers'][] = ['i' => $i, 'n' => $i + 1, 'text' => $q['answers'][$i] ?? ''];
                }
                break;
            case 'numerical':
                $data['answer'] = is_numeric($q['answer'] ?? null) ? format_float((float) $q['answer'], -1) : '';
                $data['tolerance'] = format_float((float) ($q['tolerance'] ?? 0), -1);
                break;
            case 'match':
                $data['pairs'] = [];
                // One empty row more than there are pairs, so a pair can be added without any script.
                $rows = min(self::MAX_PAIRS, max(self::MIN_PAIRS, count($q['pairs'] ?? []) + 1));
                for ($i = 0; $i < $rows; $i++) {
                    $data['pairs'][] = ['i' => $i, 'n' => $i + 1, 'left' => $q['pairs'][$i]['left'] ?? '',
                        'right' => $q['pairs'][$i]['right'] ?? ''];
                }
                break;
            case 'essay':
                $data['graderinfo'] = $q['graderinfo'] ?? '';
                break;
        }
        return $data;
    }
}
