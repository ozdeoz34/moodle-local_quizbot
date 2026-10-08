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
 * The review page of one run - the wizard's last step, and each quiz of a remedial run: the questions as cards, the
 * ticks kept between the buttons, "Rewrite this one".
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review {
    /**
     * Keeps which questions are ticked: every button of the review page sends its ticks, so that an edit or a rewrite
     * does not tick everything again.
     *
     * @param \stdClass $job as returned by job::current() or remedial::jobs()
     * @param int[] $ticked the indexes of the ticked questions
     * @return array the run's result, as job::result()
     */
    public static function keep_ticks(\stdClass $job, array $ticked): array {
        $result = job::result($job);
        $result['unticked'] = array_values(array_diff(array_keys($result['questions']), $ticked));
        $job->result = json_encode($result);
        job::save($job);
        return $result;
    }

    /**
     * "Rewrite this one": a new question of the same kind, from the same source, unlike the ones on the page now, at
     * the question's Bloom's level (the teacher may have changed it).
     *
     * @param \stdClass $job
     * @param int $i the question's index
     * @throws api_exception with a message for the teacher
     */
    public static function rewrite(\stdClass $job, int $i): void {
        $result = job::result($job);
        \core_php_time_limit::raise(120);
        $answer = (new api())->rewrite(
            (string) $job->remoteid,
            $i,
            array_column($result['questions'], 'text'),
            (string) ($result['questions'][$i]['bloom'] ?? '')
        );
        if (empty($answer['question']['type']) || $answer['question']['type'] !== $result['questions'][$i]['type']) {
            throw new api_exception(get_string('errorunreachable', 'local_quizbot'), 'bad_answer');
        }
        $old = $result['questions'][$i];
        $result['questions'][$i] = ['rewritten' => true] + $answer['question'];
        $asked = (string) ($old['bloom'] ?? '');
        if (!in_array($answer['question']['bloom'] ?? '', bloom::LEVELS, true) && in_array($asked, bloom::LEVELS, true)) {
            // Quizbot sent the new question without a level (rare): it was written at the level asked for.
            $result['questions'][$i]['bloom'] = $asked;
        }
        if (isset($old['bloomwritten'])) {
            // Rewritten at a level the teacher chose: what Quizbot first delivered still counts.
            $result['questions'][$i]['bloomwritten'] = $old['bloomwritten'];
        }
        $result['unticked'] = array_values(array_diff($result['unticked'], [$i]));
        $job->result = json_encode($result);
        job::save($job);
    }

    /**
     * What the review template shows of a run's questions: the cards, the filter by Bloom's level, and what the
     * teacher asked for that Quizbot could not deliver.
     *
     * @param \stdClass $job
     * @param \context $context where the text is formatted (MathJax and the other filters)
     * @return array part of the review template's context
     */
    public static function view(\stdClass $job, \context $context): array {
        $result = job::result($job);
        $show = fn (string $text): string => trim($text) === '' ? '' : format_text(s($text), FORMAT_HTML, ['context' => $context,
            'para' => false]);
        $cards = [];
        foreach ($result['questions'] as $index => $q) {
            $card = [
                'index' => $index,
                'number' => $index + 1,
                'typelabel' => get_string('type_' . $q['type'], 'local_quizbot'),
                'source' => $result['names'][$q['source']] ?? '',
                'text' => $q['text'],
                'feedback' => $q['feedback'] ?? '',
                'lines' => [],
                'ticked' => !in_array($index, $result['unticked'], true),
                'edited' => !empty($q['edited']),
                'rewritten' => !empty($q['rewritten']),
                // The Bloom's level the question really tests (1.1); questions written before 1.1 have none.
                'skill' => bloom::name((string) ($q['bloom'] ?? '')),
                'skillid' => in_array($q['bloom'] ?? '', bloom::LEVELS, true) ? $q['bloom'] : 'none',
            ];
            switch ($q['type']) {
                case 'multichoice':
                    foreach ($q['options'] as $o) {
                        $card['lines'][] = ['text' => $o['text'], 'correct' => !empty($o['correct']),
                            'note' => $o['feedback'] ?? ''];
                    }
                    break;
                case 'truefalse':
                    $card['lines'][] = ['text' => get_string('true', 'qtype_truefalse'), 'correct' => $q['answer'] === true,
                        'note' => ''];
                    $card['lines'][] = ['text' => get_string('false', 'qtype_truefalse'), 'correct' => $q['answer'] === false,
                        'note' => ''];
                    break;
                case 'shortanswer':
                    foreach ($q['answers'] as $a) {
                        $card['lines'][] = ['text' => $a, 'correct' => true, 'note' => ''];
                    }
                    break;
                case 'numerical':
                    $tolerance = (float) ($q['tolerance'] ?? 0);
                    $card['lines'][] = [
                        'text' => format_float($q['answer'], -1) . ($tolerance > 0 ? ' ('
                            . get_string('accepted', 'local_quizbot', [
                            'from' => format_float($q['answer'] - $tolerance, -1),
                            'to' => format_float($q['answer'] + $tolerance, -1)]) . ')' : ''),
                        'correct' => true,
                        'note' => '',
                    ];
                    break;
                case 'match':
                    foreach ($q['pairs'] as $p) {
                        // The line takes its direction from its own first letters (dir="auto" in the template), whatever
                        // the page's language: the arrow points the same way, from the first item to its match.
                        $rtl = preg_match('/^[^\p{L}]*[\p{Hebrew}\p{Arabic}\p{Syriac}\p{Thaana}\p{Nko}]/u', $p['left']);
                        $card['lines'][] = ['text' => $p['left'] . ($rtl ? '  ←  ' : '  →  ') . $p['right'], 'correct' => false,
                            'note' => '', 'plain' => true];
                    }
                    break;
                case 'essay':
                    $card['graderinfo'] = $q['graderinfo'] ?? '';
                    break;
            }
            // Maths written as \( ... \) is shown as formulas by Moodle's own filters (MathJax), as in the quiz itself. The
            // text is escaped first, so nothing in it can act as markup; the template prints the result as it is.
            foreach (['text', 'feedback', 'graderinfo'] as $field) {
                if (isset($card[$field])) {
                    $card[$field] = $show($card[$field]);
                }
            }
            foreach ($card['lines'] as $n => $line) {
                $card['lines'][$n]['text'] = $show($line['text']);
                $card['lines'][$n]['note'] = $line['note'] === '' ? '' : $show($line['note']);
            }
            $cards[] = $card;
        }

        // Bloom's levels: what the teacher asked for, what came back, and a filter by level.
        $bylevel = array_count_values(array_column($cards, 'skillid'));
        $chips = [];
        foreach (bloom::LEVELS as $level) {
            if (!empty($bylevel[$level])) {
                $chips[] = ['id' => $level, 'label' => bloom::name($level), 'n' => $bylevel[$level]];
            }
        }
        $asked = [];
        $missed = [];
        if (!empty($job->optionsdata['bloomon'])) {
            // Short of what was asked for: counted on the levels Quizbot wrote at, not on the teacher's own changes.
            $written = array_count_values(array_map(
                fn ($q) => (string) ($q['bloomwritten'] ?? ($q['bloom'] ?? '')),
                $result['questions']
            ));
            foreach (bloom::clean($job->optionsdata['skills'] ?? []) as $level => $n) {
                if ($n > 0) {
                    $asked[] = bloom::name($level) . ' ' . $n;
                    if (($written[$level] ?? 0) < $n) {
                        $missed[] = ['n' => $n - ($written[$level] ?? 0), 'level' => bloom::name($level)];
                    }
                }
            }
        }
        return [
            'askedtext' => $asked ? get_string('bloomasked', 'local_quizbot', implode(' · ', $asked)) : '',
            'missed' => array_map(fn ($m) => ['text' => get_string('bloommissed', 'local_quizbot', $m)], $missed),
            'hasmissed' => (bool) $missed,
            'chips' => $chips,
            'haschips' => count($chips) > 1,
            'count' => count($cards),
            'hasquestions' => (bool) $cards,
            'questions' => $cards,
            'notes' => $result['notes'],
            'hasnotes' => (bool) $result['notes'],
        ];
    }
}
