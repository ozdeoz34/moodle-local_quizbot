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
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * A student's own results (1.1): the card on the review page of an attempt, and "My progress" in a course.
 *
 * Only what the quiz lets the student see: an attempt whose marks the quiz's review options hide (until the quiz
 * closes, say) is left out, as Moodle itself would. "My progress" uses the LATEST answer to each question, so it
 * moves when the student tries again. No other student's result is ever used. Topics are the material Quizbot wrote a
 * question from; questions Quizbot did not write count in the quiz scores only.
 *
 * gather_*() read Moodle; card() and progress() do the sums on plain arrays, so they can be tested on their own.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student {
    /** @var int a topic at or above this % is "Strong" */
    public const STRONG = 75;

    /** @var int at or above this % "Getting there", below it "Revise" */
    public const GETTING = 50;

    /** @var float a question counts as right from this mark (its share of its marks) */
    public const RIGHT = 0.5;

    /** @var int questions to look at again, at most */
    public const LOOK_AGAIN = 3;

    /**
     * Can the student see the marks of this attempt now, by the quiz's review options?
     *
     * @param \stdClass $quiz
     * @param \stdClass $attempt
     * @param \context_module $context
     * @return bool
     */
    public static function marks_visible(\stdClass $quiz, \stdClass $attempt, \context_module $context): bool {
        $options = quiz_get_review_options($quiz, $attempt, $context);
        return $options->marks >= \question_display_options::MARK_AND_MAX;
    }

    /**
     * The latest mark of every question in some attempts.
     *
     * @param int[] $usages question usage ids (quiz_attempts.uniqueid)
     * @return array [usage, slot, question, mark (0..1, null = to be graded)]
     */
    public static function answers(array $usages): array {
        if (!$usages) {
            return [];
        }
        $steps = (new \question_engine_data_mapper())->load_questions_usages_latest_steps(
            new \qubaid_list(array_values($usages)),
            null,
            'qas.id, qa.questionusageid, qa.slot, qa.questionid, qas.state, qas.fraction'
        );
        $answers = [];
        foreach ($steps as $step) {
            $answers[] = ['usage' => (int) $step->questionusageid, 'slot' => (int) $step->slot,
                'question' => (int) $step->questionid, 'mark' => analysis::mark((string) $step->state, $step->fraction)];
        }
        return $answers;
    }

    /**
     * A topic's result as the student reads it: right answers out of how many, that as a %, and the word for it. The %
     * and the word follow "x of y right", so part marks never make "2 of 4 right" say "Revise".
     *
     * @param float[] $marks
     * @return array [right, total, pct, word: strong | getting | revise]
     */
    public static function result(array $marks): array {
        $total = count($marks);
        $right = count(array_filter($marks, fn ($m) => $m >= self::RIGHT));
        $pct = $total ? $right / $total * 100 : 0.0;
        return [
            'right' => $right,
            'total' => $total,
            'pct' => $pct,
            'word' => $pct >= self::STRONG ? 'strong' : ($pct >= self::GETTING ? 'getting' : 'revise'),
        ];
    }

    /**
     * The card for one attempt: its result by topic, and the topic to revise first.
     *
     * @param array $answers [slot, question, mark] of this attempt
     * @param array $questions question id => as analysis::questions()
     * @return array [topics: [name, source, sourcecmid, right, total, pct, word], weakest first; revise: one of them or
     *               null when every topic is strong; pending: answers still to be graded]
     */
    public static function card(array $answers, array $questions): array {
        $topics = [];
        $pending = 0;
        foreach ($answers as $a) {
            $q = $questions[$a['question']] ?? [];
            $topic = analysis::topic_of($q);
            if ($topic === '') {
                continue;
            }
            if ($a['mark'] === null) {
                $pending++;
                continue;
            }
            $topics[$topic]['marks'][] = $a['mark'];
            $topics[$topic]['source'] = $q['source'];
            $topics[$topic]['sourcecmid'] = $q['sourcecmid'];
        }
        $list = [];
        foreach ($topics as $name => $t) {
            $list[] = ['name' => (string) $name, 'source' => $t['source'], 'sourcecmid' => $t['sourcecmid']]
                + self::result($t['marks']);
        }
        usort($list, fn ($a, $b) => [$a['pct'], -$a['total']] <=> [$b['pct'], -$b['total']]);
        $revise = $list && $list[0]['word'] !== 'strong' ? $list[0] : null;
        return ['topics' => $list, 'revise' => $revise, 'pending' => $pending];
    }

    /**
     * "My progress" in a course, from the student's own attempts.
     *
     * @param array $quizzes quizzes the student can see, in course order: [id, cmid, name, timeopen, timeclose, sumgrades]
     * @param array $attempts the student's finished attempts, oldest first: [id, quiz, usage, sumgrades, timefinish,
     *              visible (marks may be shown)]
     * @param array $answers [usage, slot, question, mark] of the visible attempts
     * @param array $questions question id => as analysis::questions()
     * @param int $now
     * @return array
     */
    public static function progress(array $quizzes, array $attempts, array $answers, array $questions, int $now): array {
        $byquiz = [];
        foreach ($attempts as $a) {
            $byquiz[$a['quiz']][] = $a;
        }

        // Each quiz's latest attempt the student may see the marks of.
        $done = [];
        foreach ($quizzes as $quiz) {
            $mine = $byquiz[$quiz['id']] ?? [];
            if (!$mine) {
                continue;
            }
            $seen = array_values(array_filter($mine, fn ($a) => $a['visible']));
            $latest = $seen ? end($seen) : null;
            $done[] = [
                'quiz' => $quiz,
                'when' => end($mine)['timefinish'],
                'pct' => $latest && $latest['sumgrades'] !== null && $quiz['sumgrades'] > 0
                    ? $latest['sumgrades'] / $quiz['sumgrades'] * 100 : null,
            ];
        }
        usort($done, fn ($a, $b) => $a['when'] <=> $b['when']);
        $scores = array_values(array_filter(array_column($done, 'pct'), fn ($p) => $p !== null));

        // The next quiz: one open now that is not done, else the next to open.
        $next = null;
        foreach ($quizzes as $quiz) {
            if (empty($byquiz[$quiz['id']]) && (!$quiz['timeclose'] || $quiz['timeclose'] > $now)) {
                if ($quiz['timeopen'] <= $now) {
                    $next = $quiz;
                    break;
                }
                if (!$next || $quiz['timeopen'] < $next['timeopen']) {
                    $next = $quiz;
                }
            }
        }

        // The latest answer to each question, wherever it was answered.
        $usages = array_column($attempts, null, 'usage');
        $latest = [];
        foreach ($answers as $a) {
            $q = $questions[$a['question']] ?? null;
            $attempt = $usages[$a['usage']] ?? null;
            if (!$q || !$attempt || $a['mark'] === null) {
                continue;
            }
            $key = $q['entry'] ?: 'q' . $a['question'];
            if (!isset($latest[$key]) || $latest[$key]['time'] <= $attempt['timefinish']) {
                $latest[$key] = ['mark' => $a['mark'], 'time' => $attempt['timefinish'], 'question' => $a['question'],
                    'attempt' => $attempt['id'], 'quiz' => $attempt['quiz'], 'slot' => $a['slot']];
            }
        }

        $topics = [];
        $skills = [];
        foreach ($latest as $l) {
            $q = $questions[$l['question']];
            $topic = analysis::topic_of($q);
            if ($topic !== '') {
                $topics[$topic]['marks'][] = $l['mark'];
                $topics[$topic]['source'] = $q['source'];
                $topics[$topic]['sourcecmid'] = $q['sourcecmid'];
                $topics[$topic]['answers'][] = $l;
            }
            if (in_array($q['bloom'], bloom::LEVELS, true)) {
                $skills[$q['bloom']][] = $l['mark'];
            }
        }
        $topiclist = [];
        foreach ($topics as $name => $t) {
            $topiclist[] = ['name' => (string) $name, 'source' => $t['source'], 'sourcecmid' => $t['sourcecmid']]
                + self::result($t['marks']);
        }
        usort($topiclist, fn ($a, $b) => [$a['pct'], -$a['total']] <=> [$b['pct'], -$b['total']]);
        $skilllist = [];
        foreach (bloom::LEVELS as $level) {
            if (isset($skills[$level])) {
                $skilllist[] = ['level' => $level] + self::result($skills[$level]);
            }
        }

        // Questions to look at again: wrong last time, from the weakest topic, the most recent first.
        $again = [];
        $weakest = $topiclist && $topiclist[0]['word'] !== 'strong' ? $topiclist[0] : null;
        if ($weakest) {
            $wrong = array_filter($topics[$weakest['name']]['answers'], fn ($l) => $l['mark'] < self::RIGHT);
            usort($wrong, fn ($a, $b) => $b['time'] <=> $a['time']);
            $again = array_slice(array_values($wrong), 0, self::LOOK_AGAIN);
        }

        return [
            'quizcount' => count($quizzes),
            'done' => count($done),
            'next' => $next,
            'average' => $scores ? array_sum($scores) / count($scores) : null,
            'trend' => array_slice($scores, -4),
            'quizzes' => $done,
            'topics' => $topiclist,
            'strongest' => $topiclist ? end($topiclist) : null,
            'revise' => $weakest,
            'skills' => $skilllist,
            'again' => $again,
        ];
    }

    /**
     * Reads what card() needs for one attempt; null when the card is not to be shown (an unfinished attempt, a
     * preview, marks the student may not see yet, or no question Quizbot wrote).
     *
     * @param \stdClass $attempt quiz_attempts row
     * @param \stdClass $quiz
     * @param \cm_info $cm
     * @return array|null [card, questions]
     */
    public static function gather_card(\stdClass $attempt, \stdClass $quiz, \cm_info $cm): ?array {
        $context = \context_module::instance($cm->id);
        if ($attempt->state !== 'finished' || $attempt->preview || !self::marks_visible($quiz, $attempt, $context)) {
            return null;
        }
        $answers = self::answers([(int) $attempt->uniqueid]);
        $questions = analysis::questions(array_column($answers, 'question'));
        $card = self::card($answers, $questions);
        return $card['topics'] ? $card : null;
    }

    /**
     * Reads what progress() needs: the quizzes of the course the student can see, and their attempts at them.
     *
     * @param \stdClass $course
     * @param int $userid
     * @return array progress()'s result, plus 'attempts' (attempt id => [cmid, quiz record]) for links
     */
    public static function gather_progress(\stdClass $course, int $userid): array {
        global $DB;

        $modinfo = get_fast_modinfo($course, $userid);
        $quizzes = [];
        $records = [];
        foreach ($modinfo->get_instances_of('quiz') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $records[$cm->instance] = $DB->get_record('quiz', ['id' => $cm->instance]);
            $quizzes[] = ['id' => (int) $cm->instance, 'cmid' => (int) $cm->id, 'name' => $cm->get_formatted_name(),
                'timeopen' => (int) $records[$cm->instance]->timeopen, 'timeclose' => (int) $records[$cm->instance]->timeclose,
                'sumgrades' => (float) $records[$cm->instance]->sumgrades];
        }
        $attempts = [];
        $links = [];
        if ($records) {
            [$in, $params] = $DB->get_in_or_equal(array_keys($records), SQL_PARAMS_NAMED);
            $rows = $DB->get_records_select(
                'quiz_attempts',
                "quiz $in AND userid = :userid AND preview = 0 AND state = :state",
                $params + ['userid' => $userid, 'state' => 'finished'],
                'timefinish, id'
            );
            $cmids = array_column($quizzes, 'cmid', 'id');
            foreach ($rows as $row) {
                $context = \context_module::instance($cmids[$row->quiz]);
                $attempts[] = ['id' => (int) $row->id, 'quiz' => (int) $row->quiz, 'usage' => (int) $row->uniqueid,
                    'sumgrades' => $row->sumgrades === null ? null : (float) $row->sumgrades,
                    'timefinish' => (int) $row->timefinish,
                    'visible' => self::marks_visible($records[$row->quiz], $row, $context)];
                $links[(int) $row->id] = ['cmid' => $cmids[$row->quiz], 'quiz' => $records[$row->quiz], 'row' => $row];
            }
        }
        $visible = array_filter($attempts, fn ($a) => $a['visible']);
        $answers = self::answers(array_column($visible, 'usage'));
        $questions = analysis::questions(array_column($answers, 'question'));
        return self::progress($quizzes, $attempts, $answers, $questions, time()) + ['links' => $links,
            'questions' => $questions];
    }

    /**
     * Where a topic's material is in the course, for a student to open; null when it is not a course item they can see.
     * The record holds the course item from 1.1 on; older questions are matched by the material's name.
     *
     * @param \stdClass $course
     * @param int $userid
     * @param int $sourcecmid
     * @param string $source the material's name
     * @return \moodle_url|null
     */
    public static function material_url(\stdClass $course, int $userid, int $sourcecmid, string $source): ?\moodle_url {
        $modinfo = get_fast_modinfo($course, $userid);
        $cms = $modinfo->get_cms();
        $cm = $sourcecmid && isset($cms[$sourcecmid]) ? $cms[$sourcecmid] : null;
        if (!$cm && $source !== '') {
            foreach ($cms as $candidate) {
                if ($candidate->get_formatted_name() === $source) {
                    $cm = $candidate;
                    break;
                }
            }
        }
        return $cm && $cm->uservisible && $cm->url ? $cm->url : null;
    }
}
