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
 * Quizbot Analysis of a whole course (1.1): every quiz in it that holds questions Quizbot wrote, topics and skills
 * across them, and the students who need attention. Built from each quiz's own analysis (analysis::for_quiz(), so
 * the same rules: each student's first finished attempt, previews never).
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_analysis {
    /** @var int a student whose average is below this needs attention... */
    public const ATTENTION = 60;

    /** @var int ...over at least this many quizzes */
    public const ATTENTION_QUIZZES = 2;

    /** @var int scores closer together than this (points) are "steady" */
    public const STEADY = 5;

    /**
     * The quizzes of a course that hold questions Quizbot wrote, in course order, that the current user can see. The
     * remedial quizzes are left out: they are practice for a few students, not a quiz of the class.
     *
     * @param \stdClass $course
     * @return \cm_info[]
     */
    public static function quizzes(\stdClass $course): array {
        global $DB;

        $ids = $DB->get_fieldset_sql("SELECT DISTINCT qs.quizid
              FROM {quiz_slots} qs
              JOIN {quiz} q ON q.id = qs.quizid
              JOIN {question_references} qr ON qr.component = 'mod_quiz' AND qr.questionarea = 'slot' AND qr.itemid = qs.id
              JOIN {local_quizbot_question} l ON l.questionbankentryid = qr.questionbankentryid
             WHERE q.course = ?", [$course->id]);
        $remedial = remedial::quiz_cmids((int) $course->id);
        $cms = [];
        foreach (get_fast_modinfo($course)->get_instances_of('quiz') as $cm) {
            if ($cm->uservisible && in_array($cm->instance, $ids) && !in_array((int) $cm->id, $remedial, true)) {
                $cms[] = $cm;
            }
        }
        return $cms;
    }

    /**
     * Reads every quiz's analysis for some classes of the course ([] = everybody).
     *
     * @param \stdClass $course
     * @param int[] $groupids
     * @return array compute()'s result
     */
    public static function gather(\stdClass $course, array $groupids): array {
        global $DB;

        $quizzes = [];
        foreach (self::quizzes($course) as $cm) {
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
            $taken = $DB->record_exists('quiz_attempts', ['quiz' => $quiz->id, 'preview' => 0, 'state' => 'finished']);
            $quizzes[] = ['cmid' => (int) $cm->id, 'name' => $cm->get_formatted_name(), 'timeopen' => (int) $quiz->timeopen,
                'analysis' => $taken ? analysis::for_quiz($cm, $quiz, $groupids) : null];
        }
        $context = \context_course::instance($course->id);
        $classsize = count(get_enrolled_users($context, 'mod/quiz:attempt', $groupids ?: 0, 'u.id', 'u.id', 0, 0, true));
        return self::compute($quizzes, $classsize);
    }

    /**
     * The sums.
     *
     * @param array $quizzes in course order: [cmid, name, timeopen, analysis (analysis::compute()'s result, or null when
     *              nobody has finished it)]
     * @param int $classsize
     * @return array
     */
    public static function compute(array $quizzes, int $classsize): array {
        $rows = [];
        $averages = [];
        $topics = [];
        $skills = [];
        $students = [];
        foreach ($quizzes as $quiz) {
            $a = $quiz['analysis'];
            $taken = $a && $a['finished'] > 0;
            $weakest = null;
            if ($taken) {
                foreach ($a['topics'] as $t) {
                    if (!$t['other'] && $t['pct'] !== null) {
                        $weakest = $weakest ?? $t;
                        $topics[$t['name']]['sum'] = ($topics[$t['name']]['sum'] ?? 0) + ($t['sum'] ?? 0);
                        $topics[$t['name']]['n'] = ($topics[$t['name']]['n'] ?? 0) + ($t['n'] ?? 0);
                        $topics[$t['name']]['quizzes'][$quiz['name']] = true;
                    }
                }
                foreach ($a['skills'] as $s) {
                    if ($s['level'] !== '') {
                        $skills[$s['level']]['sum'] = ($skills[$s['level']]['sum'] ?? 0) + ($s['sum'] ?? 0);
                        $skills[$s['level']]['n'] = ($skills[$s['level']]['n'] ?? 0) + ($s['n'] ?? 0);
                    }
                }
                if ($a['average'] !== null) {
                    $averages[] = $a['average'];
                }
                // An analysis kept from before 1.1d has no per-student figures; caches are cleared on upgrade anyway.
                foreach ($a['scores'] ?? [] as $user => $score) {
                    $students[$user]['scores'][] = ['time' => $a['finish'][$user] ?? 0, 'score' => $score];
                }
                foreach ($a['usertopics'] ?? [] as $user => $marks) {
                    foreach ($marks as $topic => $list) {
                        $students[$user]['topics'][$topic] = array_merge($students[$user]['topics'][$topic] ?? [], $list);
                    }
                }
            }
            $rows[] = ['cmid' => $quiz['cmid'], 'name' => $quiz['name'], 'timeopen' => $quiz['timeopen'], 'taken' => $taken,
                'finished' => $taken ? $a['finished'] : 0, 'average' => $taken ? $a['average'] : null,
                'weakest' => $weakest ? ['name' => $weakest['name'], 'pct' => $weakest['pct']] : null];
        }

        $topiclist = [];
        foreach ($topics as $name => $t) {
            if (!$t['n']) {
                continue;
            }
            $topiclist[] = ['name' => (string) $name, 'pct' => $t['sum'] / $t['n'] * 100,
                'quizzes' => array_keys($t['quizzes'])];
        }
        usort($topiclist, fn ($a, $b) => $a['pct'] <=> $b['pct']);
        $skilllist = [];
        foreach (bloom::LEVELS as $level) {
            if (!empty($skills[$level]['n'])) {
                $skilllist[] = ['level' => $level, 'pct' => $skills[$level]['sum'] / $skills[$level]['n'] * 100];
            }
        }

        // Students who need attention: a low average over at least two quizzes, with the way it is going.
        $attention = [];
        foreach ($students as $user => $s) {
            $scores = $s['scores'] ?? [];
            if (count($scores) < self::ATTENTION_QUIZZES) {
                continue;
            }
            usort($scores, fn ($a, $b) => $a['time'] <=> $b['time']);
            $series = array_column($scores, 'score');
            $average = array_sum($series) / count($series);
            if ($average < self::ATTENTION) {
                $attention[] = ['user' => $user, 'done' => count($series), 'average' => $average, 'series' => $series,
                    'direction' => self::direction($series), 'revise' => analysis::revise_first($s['topics'] ?? [])];
            }
        }
        usort($attention, fn ($a, $b) => $a['average'] <=> $b['average']);

        return [
            'quizzes' => $rows,
            'quizcount' => count($rows),
            'taken' => count(array_filter($rows, fn ($r) => $r['taken'])),
            'classsize' => $classsize,
            'average' => $averages ? array_sum($averages) / count($averages) : null,
            'trend' => $averages,
            'topics' => $topiclist,
            'skills' => $skilllist,
            'attention' => $attention,
        ];
    }

    /**
     * Which way a student's scores are going: rising, falling, steady or upanddown.
     *
     * @param float[] $series oldest first
     * @return string
     */
    public static function direction(array $series): string {
        if (max($series) - min($series) < self::STEADY) {
            return 'steady';
        }
        $up = true;
        $down = true;
        for ($i = 1; $i < count($series); $i++) {
            $up = $up && $series[$i] > $series[$i - 1];
            $down = $down && $series[$i] < $series[$i - 1];
        }
        return $up ? 'rising' : ($down ? 'falling' : 'upanddown');
    }
}
