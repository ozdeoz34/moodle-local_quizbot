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
 * How the site uses Quizbot (1.1), for its administrators: teachers, courses, questions added week by week, and how
 * students did on those questions. From the plugin's own record of the questions added (local_quizbot_question) and
 * Moodle's quiz attempts; students are only counted, never listed.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class usage {
    /** @var int weeks in the chart */
    public const WEEKS = 8;

    /** @var int a teacher is "active" with Quizbot use in this many days */
    public const ACTIVE_DAYS = 30;

    /**
     * Everything the usage page shows, since a moment (the start of the licence year).
     *
     * @param int $since
     * @param int $now
     * @return array
     */
    public static function gather(int $since, int $now): array {
        global $DB;

        $rows = $DB->get_records_select(
            'local_quizbot_question',
            'timecreated >= ?',
            [$since],
            'timecreated',
            'id, courseid, cmid, userid, timecreated'
        );
        $teachers = [];
        $courses = [];
        $weeks = [];
        $weekstart = strtotime('monday this week', $now) - (self::WEEKS - 1) * WEEKSECS;
        for ($w = 0; $w < self::WEEKS; $w++) {
            $weeks[$w] = ['start' => $weekstart + $w * WEEKSECS, 'n' => 0];
        }
        foreach ($rows as $r) {
            if ($r->userid) {
                $t = &$teachers[$r->userid];
                $t['questions'] = ($t['questions'] ?? 0) + 1;
                $t['courses'][$r->courseid] = true;
                $t['last'] = max($t['last'] ?? 0, (int) $r->timecreated);
                unset($t);
            }
            $courses[$r->courseid]['questions'] = ($courses[$r->courseid]['questions'] ?? 0) + 1;
            $courses[$r->courseid]['quizzes'][$r->cmid] = true;
            $w = (int) floor(($r->timecreated - $weekstart) / WEEKSECS);
            if ($w >= 0 && $w < self::WEEKS) {
                $weeks[$w]['n']++;
            }
        }
        // Teachers who used the wizard but have added nothing (their runs are kept for a few weeks).
        foreach (
            $DB->get_records_sql('SELECT userid, MAX(timemodified) AS last FROM {local_quizbot_job}
                WHERE timemodified >= ? GROUP BY userid', [$since]) as $j
        ) {
            if (!isset($teachers[$j->userid])) {
                $teachers[$j->userid] = ['questions' => 0, 'courses' => [], 'last' => (int) $j->last, 'triedonly' => true];
            }
        }

        // How students did on the questions Quizbot wrote: finished attempts, previews never, essays once graded.
        $from = "FROM {quiz_attempts} quiza
                 JOIN {quiz} q ON q.id = quiza.quiz
                 JOIN {question_attempts} qa ON qa.questionusageid = quiza.uniqueid
                 JOIN {question_versions} v ON v.questionid = qa.questionid
                 JOIN {local_quizbot_question} l ON l.questionbankentryid = v.questionbankentryid
                 JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                      AND qas.sequencenumber = (SELECT MAX(s2.sequencenumber) FROM {question_attempt_steps} s2
                                                 WHERE s2.questionattemptid = qa.id)
                WHERE quiza.preview = 0 AND quiza.state = :finished AND quiza.timefinish >= :since
                      AND qas.state <> :needsgrading";
        $params = ['finished' => 'finished', 'since' => $since, 'needsgrading' => 'needsgrading'];
        $mark = 'CASE WHEN qas.fraction IS NULL OR qas.fraction < 0 THEN 0 ELSE qas.fraction END';
        $all = $DB->get_record_sql("SELECT COUNT(DISTINCT quiza.userid) AS students, AVG($mark) AS average $from", $params);
        foreach (
            $DB->get_records_sql("SELECT q.course, COUNT(DISTINCT quiza.userid) AS students, AVG($mark) AS average
                $from GROUP BY q.course", $params) as $c
        ) {
            $courses[$c->course]['students'] = (int) $c->students;
            $courses[$c->course]['average'] = $c->average === null ? null : (float) $c->average * 100;
        }

        // A quiz deleted since is no longer counted as a quiz; the questions added to it still are.
        $cmids = array_merge(...array_map(fn ($c) => array_keys($c['quizzes'] ?? []), array_values($courses)));
        $existing = [];
        if ($cmids) {
            [$in, $inparams] = $DB->get_in_or_equal($cmids);
            $existing = $DB->get_records_select('course_modules', "id $in AND deletioninprogress = 0", $inparams, '', 'id');
        }
        foreach ($courses as $courseid => $c) {
            $courses[$courseid]['quizzes'] = array_intersect_key($c['quizzes'] ?? [], $existing);
        }

        $active = count(array_filter($teachers, fn ($t) => $t['last'] >= $now - self::ACTIVE_DAYS * DAYSECS));
        uasort($teachers, fn ($a, $b) => [$b['questions'], $b['last']] <=> [$a['questions'], $a['last']]);
        uasort($courses, fn ($a, $b) => ($b['questions'] ?? 0) <=> ($a['questions'] ?? 0));
        return [
            'questions' => count($rows),
            'teachers' => $teachers,
            'active' => $active,
            'courses' => $courses,
            'quizzes' => array_sum(array_map(fn ($c) => count($c['quizzes'] ?? []), $courses)),
            'students' => (int) ($all->students ?? 0),
            'average' => isset($all->average) && $all->average !== null ? (float) $all->average * 100 : null,
            'weeks' => $weeks,
        ];
    }
}
