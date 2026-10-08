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

require_once($CFG->dirroot . '/question/engine/lib.php');

/**
 * Quizbot Analysis of one quiz, for its teachers: how the class did, by topic, by skill, by question, and who is below
 * the pass mark.
 *
 * What is measured: each student's FIRST finished attempt (previews never), so a quiz taken again does not hide what
 * the class knew the first time. A question's mark is its share of its marks (0 to 1, part marks count); an essay not
 * yet graded is left out until it is. Nothing leaves Moodle: it is all worked out here, when the page is opened, and
 * kept for 15 minutes.
 *
 * gather() reads Moodle's tables; compute() does the sums on plain arrays, so it can be tested on its own.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class analysis {
    /** @var int pass mark in % when the quiz has no "Grade to pass" */
    public const DEFAULT_PASS = 60;

    /** @var int answers a question needs before notes on it are given */
    public const MIN_ANSWERS = 8;

    /** @var int questions a topic or a skill needs before its result is taken as certain */
    public const MIN_QUESTIONS = 3;

    /** @var int above this % right a question is "very easy" */
    public const VERY_EASY = 90;

    /** @var int below this % right "few got it right" */
    public const FEW_RIGHT = 45;

    /** @var float below this a question does not separate the strong students from the weak (point-biserial) */
    public const WEAK_SEPARATION = 0.2;

    /** @var int one wrong answer given by this % of the class or more is a likely misconception */
    public const MISCONCEPTION = 30;

    /** @var int seconds a worked-out analysis is kept */
    public const TTL = 900;

    /** @var string[] the planned difficulties, easiest first */
    public const DIFFICULTIES = ['easy', 'medium', 'hard'];

    /**
     * The analysis of a quiz for some classes ([] = everybody), from the cache when it is fresh enough.
     *
     * @param \cm_info $cm the quiz
     * @param \stdClass $quiz its record
     * @param int[] $groupids
     * @param bool $fresh work it out again even if a recent one is kept
     * @return array compute()'s result, plus 'time' (when it was worked out)
     */
    public static function for_quiz(\cm_info $cm, \stdClass $quiz, array $groupids, bool $fresh = false): array {
        $cache = \cache::make('local_quizbot', 'analysis');
        sort($groupids);
        $key = 'c' . $cm->id . 'g' . implode('_', $groupids);
        $kept = $fresh ? false : $cache->get($key);
        if ($kept && $kept['time'] > time() - self::TTL) {
            return $kept;
        }
        $result = ['time' => time()] + self::compute(...self::gather($cm, $quiz, $groupids));
        $cache->set($key, $result);
        return $result;
    }

    /**
     * Reads what compute() needs from Moodle's tables.
     *
     * @param \cm_info $cm
     * @param \stdClass $quiz
     * @param int[] $groupids the classes ([] = everybody)
     * @return array the arguments of compute(), in its order
     */
    public static function gather(\cm_info $cm, \stdClass $quiz, array $groupids): array {
        global $DB;

        $context = \context_module::instance($cm->id);
        $students = array_keys(get_enrolled_users($context, 'mod/quiz:attempt', $groupids ?: 0, 'u.id', 'u.id', 0, 0, true));

        // Each student's first finished attempt.
        $attempts = [];
        if ($students) {
            [$in, $params] = $DB->get_in_or_equal($students, SQL_PARAMS_NAMED);
            $rows = $DB->get_records_select(
                'quiz_attempts',
                "quiz = :quiz AND preview = 0 AND state = :state AND userid $in",
                ['quiz' => $quiz->id, 'state' => 'finished'] + $params,
                'userid, attempt',
                'id, uniqueid, userid, attempt, sumgrades, timefinish'
            );
            foreach ($rows as $row) {
                if (!isset($attempts[$row->userid])) {
                    $attempts[$row->userid] = [
                        'attemptid' => (int) $row->id,
                        'usage' => (int) $row->uniqueid,
                        'sumgrades' => $row->sumgrades === null ? null : (float) $row->sumgrades,
                        'timefinish' => (int) $row->timefinish,
                    ];
                }
            }
        }

        // The latest state of every question in those attempts.
        $answers = [];
        if ($attempts) {
            $users = array_combine(array_column($attempts, 'usage'), array_keys($attempts));
            $steps = (new \question_engine_data_mapper())->load_questions_usages_latest_steps(
                new \qubaid_list(array_keys($users)),
                null,
                'qas.id, qa.questionusageid, qa.slot, qa.questionid, qa.maxmark, qa.responsesummary, qas.state, qas.fraction'
            );
            foreach ($steps as $step) {
                $answers[] = [
                    'user' => $users[$step->questionusageid],
                    'slot' => (int) $step->slot,
                    'question' => (int) $step->questionid,
                    'max' => (float) $step->maxmark,
                    'mark' => self::mark((string) $step->state, $step->fraction),
                    'response' => trim((string) $step->responsesummary),
                ];
            }
        }

        $questions = self::questions(array_column($answers, 'question'));

        $gradepass = (float) $DB->get_field('grade_items', 'gradepass', ['itemtype' => 'mod', 'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id, 'itemnumber' => 0]);
        $pass = $gradepass > 0 && $quiz->grade > 0 ? $gradepass / $quiz->grade * 100 : null;

        return [count($students), $attempts, $answers, $questions, (float) $quiz->sumgrades, $pass];
    }

    /**
     * Questions with what Quizbot knows about the ones it wrote (also used for students' own views).
     *
     * @param int[] $qids question ids
     * @return array question id => [text (plain, maths readable), name, qtype, entry (question bank entry), quizbot,
     *               source, sourcecmid, topic, bloom, difficulty]
     */
    public static function questions(array $qids): array {
        global $DB;

        $qids = array_values(array_unique(array_map('intval', $qids)));
        if (!$qids) {
            return [];
        }
        $entries = $DB->get_records_list('question_versions', 'questionid', $qids, '', 'questionid, questionbankentryid');
        $labels = $DB->get_records_list(
            'local_quizbot_question',
            'questionbankentryid',
            array_values(array_unique(array_column($entries, 'questionbankentryid'))),
            '',
            'questionbankentryid, sourcename, sourcecmid, topic, bloom, difficulty'
        );
        $questions = [];
        foreach ($DB->get_records_list('question', 'id', $qids, '', 'id, name, qtype, questiontext') as $q) {
            $entry = isset($entries[$q->id]) ? (int) $entries[$q->id]->questionbankentryid : 0;
            $label = $labels[$entry] ?? null;
            $questions[(int) $q->id] = [
                // Plain text, with maths made readable (\(O_2\) becomes O₂): these pages show no formulas.
                'text' => \core_text::substr(question_saver::readable(trim(html_to_text(
                    format_string($q->questiontext),
                    0,
                    false
                ))), 0, 400),
                'name' => format_string($q->name),
                'qtype' => $q->qtype,
                'entry' => $entry,
                'quizbot' => (bool) $label,
                'source' => $label ? (string) $label->sourcename : '',
                'sourcecmid' => $label ? (int) $label->sourcecmid : 0,
                'topic' => $label ? (string) $label->topic : '',
                'bloom' => $label ? (string) $label->bloom : '',
                'difficulty' => $label ? (string) $label->difficulty : '',
            ];
        }
        return $questions;
    }

    /**
     * A question's mark as a share of its marks, from its latest state: null while it waits to be graded by hand, 0
     * when it was not answered.
     *
     * @param string $state
     * @param mixed $fraction
     * @return float|null
     */
    public static function mark(string $state, $fraction): ?float {
        if ($state === 'needsgrading') {
            return null;
        }
        return $fraction === null ? 0.0 : max(0.0, min(1.0, (float) $fraction));
    }

    /**
     * The sums.
     *
     * @param int $classsize students who may attempt the quiz (in the group)
     * @param array $attempts user id => [attemptid, usage, sumgrades (null until fully graded), timefinish]
     * @param array $answers [user, slot, question, max, mark (0..1, null = to be graded), response]
     * @param array $questions question id => [text, name, qtype, quizbot, source, topic, bloom, difficulty]
     * @param float $total the quiz's total marks
     * @param float|null $pass the pass mark in %, null when the quiz has none
     * @return array
     */
    public static function compute(
        int $classsize,
        array $attempts,
        array $answers,
        array $questions,
        float $total,
        ?float $pass
    ): array {
        $passdefault = $pass === null;
        $pass = $pass ?? self::DEFAULT_PASS;

        // Scores in %, for the attempts that are fully graded.
        $scores = [];
        foreach ($attempts as $user => $a) {
            if ($a['sumgrades'] !== null && $total > 0) {
                $scores[$user] = $a['sumgrades'] / $total * 100;
            }
        }
        $sorted = array_values($scores);
        sort($sorted);
        $n = count($sorted);
        $median = $n ? ($n % 2 ? $sorted[intdiv($n, 2)] : ($sorted[$n / 2 - 1] + $sorted[$n / 2]) / 2) : null;

        $bands = [];
        foreach ([[0, 20], [20, 40], [40, 60], [60, 80], [80, 101]] as [$from, $to]) {
            $bands[] = [
                'from' => $from,
                'to' => min(100, $to - 1),
                'n' => count(array_filter($scores, fn ($s) => $s >= $from && $s < $to)),
                'below' => ($from + min(100, $to - 1)) / 2 < $pass,
            ];
        }

        // Per slot, per topic, per skill, per planned difficulty.
        $slots = [];
        foreach ($answers as $a) {
            $s = &$slots[$a['slot']];
            $s['marks'] = $s['marks'] ?? [];
            $s['questions'][$a['question']] = true;
            if ($a['mark'] === null) {
                $s['pending'] = ($s['pending'] ?? 0) + 1;
                continue;
            }
            $s['marks'][$a['user']] = $a['mark'];
            $s['max'] = $a['max'];
            if ($a['mark'] < 0.5 && $a['response'] !== '') {
                $s['wrong'][$a['response']] = ($s['wrong'][$a['response']] ?? 0) + 1;
            }
            unset($s);
        }
        unset($s);
        ksort($slots);

        $rows = [];
        $topics = [];
        $skills = [];
        $levels = [];
        $byuser = [];
        foreach ($slots as $slot => $s) {
            $qids = array_keys($s['questions']);
            $q = count($qids) === 1 ? ($questions[$qids[0]] ?? []) : [];
            $topic = self::topic_of($q);
            $marks = $s['marks'];
            $count = count($marks);
            $pct = $count ? array_sum($marks) / $count * 100 : null;
            $sep = $count >= self::MIN_ANSWERS ? self::separation($marks, $s['max'] ?? 0, $attempts) : null;

            $notes = [];
            if (!empty($s['pending'])) {
                $notes[] = ['key' => 'pending', 'n' => $s['pending']];
            }
            if ($count >= self::MIN_ANSWERS) {
                if ($pct > self::VERY_EASY) {
                    $notes[] = ['key' => 'veryeasy', 'pct' => round($pct)];
                } else if ($pct < self::FEW_RIGHT) {
                    $notes[] = ['key' => 'fewright', 'pct' => round($pct)];
                }
                if ($sep !== null && $sep < self::WEAK_SEPARATION) {
                    $notes[] = ['key' => 'weak'];
                }
                if (!empty($s['wrong']) && in_array($q['qtype'] ?? '', ['multichoice', 'shortanswer', 'numerical'], true)) {
                    arsort($s['wrong']);
                    $answer = (string) array_key_first($s['wrong']);
                    $share = reset($s['wrong']) / $count * 100;
                    if ($share >= self::MISCONCEPTION) {
                        $notes[] = ['key' => $q['qtype'] === 'multichoice' ? 'chose' : 'answered', 'pct' => round($share),
                            'answer' => \core_text::substr($answer, 0, 120)];
                    }
                }
            }
            $rows[] = [
                'slot' => $slot,
                'question' => count($qids) === 1 ? $qids[0] : 0,
                'variants' => count($qids),
                'text' => $q['text'] ?? '',
                'qtype' => $q['qtype'] ?? '',
                'topic' => $topic,
                'label' => $q['topic'] ?? '',
                'bloom' => in_array($q['bloom'] ?? '', bloom::LEVELS, true) ? $q['bloom'] : '',
                'difficulty' => in_array($q['difficulty'] ?? '', self::DIFFICULTIES, true) ? $q['difficulty'] : '',
                'pct' => $pct,
                'answers' => $count,
                'pending' => (int) ($s['pending'] ?? 0),
                'sep' => $sep,
                'notes' => $notes,
            ];

            // Averages over answers, so a question many students answered counts for as many.
            $add = function (array &$group, string $key) use ($marks, $s, $slot) {
                $group[$key]['slots'][$slot] = true;
                $group[$key]['sum'] = ($group[$key]['sum'] ?? 0) + array_sum($marks);
                $group[$key]['n'] = ($group[$key]['n'] ?? 0) + count($marks);
                $group[$key]['pending'] = ($group[$key]['pending'] ?? 0) + (int) ($s['pending'] ?? 0);
            };
            $add($topics, $topic);
            $add($skills, end($rows)['bloom']);
            if (end($rows)['difficulty'] !== '') {
                $add($levels, end($rows)['difficulty']);
            }
            foreach ($marks as $user => $mark) {
                $byuser[$user][$topic][] = $mark;
            }
        }
        $pctof = fn (array $g) => $g['n'] ? $g['sum'] / $g['n'] * 100 : null;

        $topiclist = [];
        foreach ($topics as $name => $g) {
            $topiclist[] = ['name' => (string) $name, 'questions' => count($g['slots']), 'pct' => $pctof($g),
                'few' => count($g['slots']) < self::MIN_QUESTIONS, 'other' => $name === '', 'n' => $g['n'], 'sum' => $g['sum']];
        }
        // Weakest first; topics with no graded answer yet at the end.
        usort($topiclist, fn ($a, $b) => [$a['pct'] === null, $a['pct']] <=> [$b['pct'] === null, $b['pct']]);

        $skilllist = [];
        foreach (array_merge(bloom::LEVELS, ['']) as $level) {
            if (isset($skills[$level])) {
                $g = $skills[$level];
                $skilllist[] = ['level' => $level, 'questions' => count($g['slots']), 'pct' => $pctof($g),
                    'pending' => $g['pending'], 'few' => count($g['slots']) < self::MIN_QUESTIONS, 'n' => $g['n'],
                    'sum' => $g['sum']];
            }
        }

        $difficulty = [];
        foreach (self::DIFFICULTIES as $level) {
            if (!isset($levels[$level])) {
                continue;
            }
            $pct = $pctof($levels[$level]);
            $outliers = [];
            foreach ($rows as $row) {
                if ($row['difficulty'] === $level && $row['pct'] !== null && $row['answers'] >= self::MIN_ANSWERS) {
                    $way = self::verdict($level, $row['pct'], true);
                    if ($way !== 'asplanned') {
                        $outliers[] = ['slot' => $row['slot'], 'way' => $way, 'pct' => round($row['pct'])];
                    }
                }
            }
            $difficulty[] = ['level' => $level, 'questions' => count($levels[$level]['slots']), 'pct' => $pct,
                'verdict' => $pct === null ? '' : self::verdict($level, $pct, false), 'outliers' => $outliers];
        }

        // Below the pass mark, lowest first, with the topic each one should revise first.
        $below = [];
        foreach ($scores as $user => $score) {
            if ($score < $pass) {
                $below[] = ['user' => $user, 'score' => $score, 'attemptid' => $attempts[$user]['attemptid'],
                    'timefinish' => $attempts[$user]['timefinish'], 'revise' => self::revise_first($byuser[$user] ?? [])];
            }
        }
        usort($below, fn ($a, $b) => $a['score'] <=> $b['score']);

        return [
            'classsize' => $classsize,
            'finished' => count($attempts),
            'graded' => count($scores),
            'average' => $scores ? array_sum($scores) / count($scores) : null,
            'median' => $median,
            'pass' => $pass,
            'passdefault' => $passdefault,
            'passed' => count(array_filter($scores, fn ($s) => $s >= $pass)),
            'pending' => array_sum(array_column($rows, 'pending')),
            'bands' => $bands,
            'topics' => $topiclist,
            'skills' => $skilllist,
            'difficulty' => $difficulty,
            'questions' => $rows,
            'below' => $below,
            // For the course view (1.1d): every student's score and finish time, and their marks by topic.
            'scores' => $scores,
            'finish' => array_map(fn ($a) => $a['timefinish'], $attempts),
            'usertopics' => $byuser,
        ];
    }

    /**
     * The topic a question counts under: the material Quizbot wrote it from (its own topic label when the material's
     * name is not known), '' for a question Quizbot did not write.
     *
     * @param array $q
     * @return string
     */
    public static function topic_of(array $q): string {
        if (empty($q['quizbot'])) {
            return '';
        }
        return $q['source'] !== '' ? $q['source'] : ($q['topic'] !== '' ? $q['topic'] : '');
    }

    /**
     * Whether a question separates the students who did well on the rest of the quiz from those who did not: the
     * correlation (point-biserial) of its mark with the rest of the attempt's marks. Null when it cannot be said.
     *
     * @param array $marks user id => mark 0..1 on this question
     * @param float $max the question's marks in the quiz
     * @param array $attempts user id => [sumgrades, ...]
     * @return float|null
     */
    public static function separation(array $marks, float $max, array $attempts): ?float {
        $xs = [];
        $ys = [];
        foreach ($marks as $user => $mark) {
            if (isset($attempts[$user]) && $attempts[$user]['sumgrades'] !== null) {
                $xs[] = $mark;
                $ys[] = $attempts[$user]['sumgrades'] - $mark * $max;
            }
        }
        return self::correlation($xs, $ys);
    }

    /**
     * Pearson's correlation of two lists, null when either does not vary or there are fewer than three pairs.
     *
     * @param float[] $xs
     * @param float[] $ys
     * @return float|null
     */
    public static function correlation(array $xs, array $ys): ?float {
        $n = count($xs);
        if ($n < 3 || $n !== count($ys)) {
            return null;
        }
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sxy += ($xs[$i] - $mx) * ($ys[$i] - $my);
            $sxx += ($xs[$i] - $mx) ** 2;
            $syy += ($ys[$i] - $my) ** 2;
        }
        return $sxx > 0 && $syy > 0 ? $sxy / sqrt($sxx * $syy) : null;
    }

    /**
     * How a planned difficulty turned out: 'asplanned', 'harder' or 'easier'. A single question is held to wider
     * bounds than the average of a level.
     *
     * @param string $level easy, medium or hard
     * @param float $pct % right
     * @param bool $single one question rather than the level's average
     * @return string
     */
    public static function verdict(string $level, float $pct, bool $single): string {
        // Level => [below this: harder than planned, above this: easier than planned].
        $bounds = $single
            ? ['easy' => [50, 101], 'medium' => [35, 90], 'hard' => [-1, 80]]
            : ['easy' => [70, 101], 'medium' => [40, 85], 'hard' => [-1, 65]];
        [$low, $high] = $bounds[$level];
        return $pct < $low ? 'harder' : ($pct > $high ? 'easier' : 'asplanned');
    }

    /**
     * The topic a student should revise first: their lowest, among topics they answered at least two questions of
     * (any topic when there is none such). Questions Quizbot did not write are left out.
     *
     * @param array $topics topic => marks 0..1
     * @return string|null
     */
    public static function revise_first(array $topics): ?string {
        unset($topics['']);
        if (!$topics) {
            return null;
        }
        $enough = array_filter($topics, fn ($marks) => count($marks) >= 2) ?: $topics;
        $avg = array_map(fn ($marks) => array_sum($marks) / count($marks), $enough);
        asort($avg);
        return (string) array_key_first($avg);
    }
}
