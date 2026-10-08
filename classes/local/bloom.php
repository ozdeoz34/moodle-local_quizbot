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
 * Bloom's taxonomy for the questions a teacher asks for: the six levels, which kinds of question can test each of
 * them, whether a teacher's numbers can be met, and the exact plan (how many questions of each kind at each level)
 * that is sent to Quizbot. The Quizbot service checks the same table (QuestionWriter::BLOOM_FITS); keep the two the
 * same. The page script (amd/src/bloom.js) repeats the plan so the teacher sees it while typing.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bloom {
    /** @var string[] the levels, from recalling to creating */
    public const LEVELS = ['remember', 'understand', 'apply', 'analyse', 'evaluate', 'create'];

    /**
     * @var array level => the kinds of question that can test it, the most natural first (the plan takes them in this
     * order, so "remember" goes to true/false and short answers before it takes a multiple-choice question that a
     * harder level may need)
     */
    public const FITS = [
        'create' => ['essay'],
        'evaluate' => ['essay', 'multichoice'],
        'analyse' => ['match', 'multichoice', 'numerical', 'essay'],
        'apply' => ['numerical', 'shortanswer', 'multichoice', 'essay'],
        'understand' => ['multichoice', 'truefalse', 'shortanswer', 'match', 'essay'],
        'remember' => ['truefalse', 'shortanswer', 'match', 'multichoice', 'numerical'],
    ];

    /**
     * Assigns the levels to kinds of question: a largest matching, found by moving earlier choices along when a later
     * level has no free question left (augmenting paths). The most constrained levels go first.
     *
     * @param array $counts kind => number of questions asked for
     * @param array $skills level => number of questions wanted at that level
     * @return array ['plan' => [kind => [level => n]], 'left' => [kind => questions with no level asked for],
     *               'unmet' => questions at a level that could not be placed]
     */
    public static function plan(array $counts, array $skills): array {
        $left = [];
        $flow = [];
        foreach (job::TYPES as $type) {
            $left[$type] = max(0, (int) ($counts[$type] ?? 0));
            $flow[$type] = array_fill_keys(self::LEVELS, 0);
        }
        $unmet = 0;
        foreach (array_keys(self::FITS) as $level) {
            for ($k = 0; $k < max(0, (int) ($skills[$level] ?? 0)); $k++) {
                if (!self::augment($level, $left, $flow)) {
                    $unmet++;
                }
            }
        }
        $plan = [];
        foreach ($flow as $type => $levels) {
            foreach ($levels as $level => $n) {
                if ($n > 0) {
                    $plan[$type][$level] = $n;
                }
            }
        }
        return ['plan' => $plan, 'left' => $left, 'unmet' => $unmet];
    }

    /**
     * Places one more question at a level, moving earlier ones to other kinds where that frees a question.
     *
     * @param string $start the level
     * @param array $left free questions per kind (changed)
     * @param array $flow questions per kind and level so far (changed)
     * @return bool whether it could be placed
     */
    private static function augment(string $start, array &$left, array &$flow): bool {
        $parenttype = [];
        $parentlevel = [];
        $seenlevel = [$start => true];
        $seentype = [];
        $queue = [$start];
        while ($queue) {
            $level = array_shift($queue);
            foreach (self::FITS[$level] as $type) {
                if (!empty($seentype[$type])) {
                    continue;
                }
                $seentype[$type] = true;
                $parenttype[$type] = $level;
                if ($left[$type] > 0) {
                    $left[$type]--;
                    for ($t = $type, $l = $parenttype[$type];; $t = $prev, $l = $parenttype[$t]) {
                        $flow[$t][$l]++;
                        if ($l === $start) {
                            return true;
                        }
                        $prev = $parentlevel[$l];
                        $flow[$prev][$l]--;
                    }
                }
                foreach (self::LEVELS as $other) {
                    if (empty($seenlevel[$other]) && $flow[$type][$other] > 0) {
                        $seenlevel[$other] = true;
                        $parentlevel[$other] = $type;
                        $queue[] = $other;
                    }
                }
            }
        }
        return false;
    }

    /**
     * When the numbers cannot be met: the group of levels that asks for more questions than its kinds have (the
     * largest shortfall; the smallest such group when two are equal).
     *
     * @param array $counts kind => number of questions
     * @param array $skills level => number wanted
     * @return array|null ['levels' => [...], 'kinds' => [...], 'wanted' => n, 'have' => n], or null when they fit
     */
    public static function misfit(array $counts, array $skills): ?array {
        $worst = null;
        $n = count(self::LEVELS);
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $levels = [];
            foreach (self::LEVELS as $i => $level) {
                if ($mask & (1 << $i)) {
                    $levels[] = $level;
                }
            }
            $wanted = array_sum(array_map(fn ($l) => max(0, (int) ($skills[$l] ?? 0)), $levels));
            if ($wanted < 1) {
                continue;
            }
            $kinds = array_values(array_unique(array_merge(...array_map(fn ($l) => self::FITS[$l], $levels))));
            $have = array_sum(array_map(fn ($k) => max(0, (int) ($counts[$k] ?? 0)), $kinds));
            $gap = $wanted - $have;
            $smaller = $worst && $gap === $worst['gap'] && count($levels) < count($worst['levels']);
            if ($gap > 0 && (!$worst || $gap > $worst['gap'] || $smaller)) {
                $worst = ['levels' => $levels, 'kinds' => $kinds, 'wanted' => $wanted, 'have' => $have, 'gap' => $gap];
            }
        }
        return $worst;
    }

    /**
     * The sentence a teacher reads when the numbers cannot be met, or '' when they can.
     *
     * @param array $counts kind => number of questions
     * @param array $skills level => number wanted
     * @return string
     */
    public static function misfit_text(array $counts, array $skills): string {
        $worst = self::misfit($counts, $skills);
        if (!$worst) {
            return '';
        }
        $kinds = [];
        foreach (job::TYPES as $type) {
            if (in_array($type, $worst['kinds'], true)) {
                $kinds[] = get_string('type_' . $type, 'local_quizbot');
            }
        }
        return get_string('bloommisfit', 'local_quizbot', [
            'levels' => implode(', ', array_map(fn ($l) => get_string('bloom_' . $l, 'local_quizbot'), $worst['levels'])),
            'kinds' => implode(', ', $kinds),
            'wanted' => $worst['wanted'],
            'have' => $worst['have'],
        ]);
    }

    /**
     * Cleans what a teacher typed: whole numbers from 0 to the most one run may ask for, for every level.
     *
     * @param array $skills level => anything
     * @return array level => int
     */
    public static function clean(array $skills): array {
        $clean = [];
        foreach (self::LEVELS as $level) {
            $clean[$level] = max(0, min(job::MAX_QUESTIONS, (int) ($skills[$level] ?? 0)));
        }
        return $clean;
    }

    /**
     * A level's name for people, or '' for an unknown one.
     *
     * @param string $level
     * @return string
     */
    public static function name(string $level): string {
        return in_array($level, self::LEVELS, true) ? get_string('bloom_' . $level, 'local_quizbot') : '';
    }
}
