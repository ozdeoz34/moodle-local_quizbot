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
 * Remedial quizzes: from a quiz's analysis, one new quiz for each topic its students below the pass mark should revise
 * first, written by Quizbot from that topic's own material, checked by the teacher, and seen only by those students
 * (a group of their own and an access restriction). The runs of one go share a key ("remedial" in
 * local_quizbot_job); each quiz made is kept in local_quizbot_remedial, so the analysis can show how it went.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remedial {
    /** @var int questions in a remedial quiz, unless the teacher says otherwise */
    public const QUESTIONS = 8;

    /** @var int the fewest questions a remedial quiz may have */
    public const MIN_QUESTIONS = 3;

    /** @var int the most questions a remedial quiz may have */
    public const MAX_QUESTIONS = 20;

    /** @var string[] kinds a remedial quiz leaves out: an essay waits to be marked, too late for another try */
    public const LEFT_OUT = ['essay'];

    /** @var array the order of the questions in a remedial quiz: easy first, building up */
    private const STEP = ['easy' => 0, 'medium' => 1, 'hard' => 2];

    /**
     * May the current user make remedial quizzes from this quiz's analysis? They write questions with Quizbot, add
     * quizzes to the course and make groups.
     *
     * @param \context_module $context the quiz's context
     * @param \stdClass $course
     * @return bool
     */
    public static function can_make(\context_module $context, \stdClass $course): bool {
        return access::can_generate($context) && has_all_capabilities(
            ['moodle/course:manageactivities', 'mod/quiz:addinstance', 'moodle/course:managegroups'],
            \context_course::instance($course->id)
        );
    }

    /**
     * Who gets which quiz: the students below the pass mark, by the topic each should revise first; the topic most of
     * them share first. Students with no Quizbot topic to revise are left out.
     *
     * @param array $analysis analysis::compute()'s result
     * @return array [['key', 'topic', 'pct' => the class's % right on it or null, 'students' => [['user', 'score']]]]
     */
    public static function groups(array $analysis): array {
        $pct = [];
        foreach ($analysis['topics'] as $t) {
            if (empty($t['other'])) {
                $pct[$t['name']] = $t['pct'];
            }
        }
        $groups = [];
        foreach ($analysis['below'] as $b) {
            $topic = (string) ($b['revise'] ?? '');
            if ($topic === '') {
                continue;
            }
            $groups[$topic] = $groups[$topic] ?? ['key' => self::key($topic), 'topic' => $topic, 'pct' => $pct[$topic] ?? null,
                'students' => []];
            $groups[$topic]['students'][] = ['user' => (int) $b['user'], 'score' => (float) $b['score']];
        }
        uasort($groups, fn ($a, $b) => [count($b['students']), $a['topic']] <=> [count($a['students']), $b['topic']]);
        return array_values($groups);
    }

    /**
     * A short key for a topic, for the page's form.
     *
     * @param string $topic
     * @return string
     */
    public static function key(string $topic): string {
        return substr(sha1($topic), 0, 10);
    }

    /**
     * The kinds of question the quiz has that Quizbot writes, and how many of each, essays left out.
     *
     * @param array $analysis analysis::compute()'s result
     * @return array kind => number, in job::TYPES order; multiple choice when there is none
     */
    public static function kinds(array $analysis): array {
        $found = [];
        foreach ($analysis['questions'] as $q) {
            $found[$q['qtype']] = ($found[$q['qtype']] ?? 0) + 1;
        }
        $kinds = [];
        foreach (job::TYPES as $type) {
            if (!empty($found[$type]) && !in_array($type, self::LEFT_OUT, true)) {
                $kinds[$type] = $found[$type];
            }
        }
        return $kinds ?: ['multichoice' => 1];
    }

    /**
     * How many questions of each kind: one of each first (as far as the number goes, the most used kind first), the
     * rest as the original quiz shares them out, each kind within its own limit.
     *
     * @param array $weights kind => how many the original quiz has
     * @param int $n questions in all
     * @return array kind => number, in job::TYPES order
     */
    public static function counts(array $weights, int $n): array {
        $weights = array_filter($weights, fn ($w) => $w > 0);
        if (!$weights || $n < 1) {
            return [];
        }
        $byuse = $weights;
        arsort($byuse);
        $counts = array_fill_keys(array_keys($byuse), 0);
        foreach (array_keys($byuse) as $type) {
            if (array_sum($counts) < $n) {
                $counts[$type] = 1;
            }
        }
        $sum = array_sum($weights);
        while (array_sum($counts) < $n) {
            $total = array_sum($counts);
            $best = null;
            $bestgap = 0.0;
            foreach ($byuse as $type => $w) {
                if ($counts[$type] >= (job::MAX_PER_TYPE[$type] ?? job::MAX_QUESTIONS)) {
                    continue;
                }
                $gap = $w / $sum * ($total + 1) - $counts[$type];
                if ($best === null || $gap > $bestgap) {
                    $best = $type;
                    $bestgap = $gap;
                }
            }
            if ($best === null) {
                break;
            }
            $counts[$best]++;
        }
        return array_filter(array_intersect_key(array_replace(array_flip(job::TYPES), $counts), $counts));
    }

    /**
     * The course item a topic's questions were written from, as a source key of the course (source_finder): the item
     * the questions remember, else one of the same name. '' when it is not in the course (any more).
     *
     * @param array $questions the quiz's questions, as analysis::questions()
     * @param string $topic as Quizbot Analysis names it
     * @param array $bykey the course's sources, from source_finder::by_key()
     * @return string
     */
    public static function material(array $questions, string $topic, array $bykey): string {
        foreach ($questions as $q) {
            if (analysis::topic_of($q) !== $topic) {
                continue;
            }
            $cmid = (int) ($q['sourcecmid'] ?? 0);
            $sameitem = '';
            $samename = '';
            foreach ($bykey as $key => $item) {
                if (($item['ref']['type'] ?? '') === 'quiz') {
                    continue;
                }
                $itemmatch = $cmid && source_finder::cmid_of((string) $key) === $cmid;
                $namematch = $item['name'] === ($q['source'] ?? '');
                if ($itemmatch && $namematch) {
                    return (string) $key;
                }
                $sameitem = $sameitem === '' && $itemmatch ? (string) $key : $sameitem;
                $samename = $samename === '' && $namematch ? (string) $key : $samename;
            }
            if ($sameitem !== '' || $samename !== '') {
                return $sameitem !== '' ? $sameitem : $samename;
            }
        }
        return '';
    }

    /**
     * What goes with the material: the quiz's questions on the topic, as an existing quiz - Quizbot then writes new
     * questions like them, never the same - and the topic itself when its material is not in the course.
     *
     * @param array $questions the quiz's questions, as analysis::questions()
     * @param string $topic
     * @param string $quizname
     * @param bool $hasmaterial
     * @return array sources as the service takes them
     */
    public static function extra_sources(array $questions, string $topic, string $quizname, bool $hasmaterial): array {
        $lines = [];
        foreach ($questions as $q) {
            if (analysis::topic_of($q) === $topic && trim($q['text']) !== '') {
                $lines[] = (count($lines) + 1) . '. ' . $q['text'];
            }
        }
        $sources = [];
        if (!$hasmaterial) {
            $sources[] = ['id' => 'topic', 'kind' => 'topic', 'name' => $topic, 'text' => \core_text::substr($topic, 0, 900)];
        }
        if ($lines) {
            $sources[] = ['id' => 'test', 'kind' => 'quiz', 'name' => $quizname, 'text' => implode("\n", $lines)];
        }
        return $sources;
    }

    /**
     * Starts one go: a run per quiz, sent to Quizbot as far as it takes them now (a licence may have only a few
     * requests under way at once); the progress page sends the others as soon as it can (send_waiting()). When
     * Quizbot refuses the go for another reason - the licence - nothing of it is kept.
     *
     * @param \stdClass $course
     * @param \cm_info $cm the quiz whose analysis it is
     * @param array $quizzes [['topic', 'students' => int[], 'counts' => kind => n, 'material' => source key or '',
     *              'extra' => more sources]]
     * @param string $level primary, secondary, university or professional
     * @return string the key of the go
     * @throws api_exception with a message for the teacher
     */
    public static function start(\stdClass $course, \cm_info $cm, array $quizzes, string $level): string {
        global $DB, $USER;

        $run = random_string(12);
        $jobs = [];
        foreach ($quizzes as $quiz) {
            $job = (object) ['userid' => $USER->id, 'courseid' => $course->id, 'cmid' => $cm->id,
                'status' => job::STATUS_DRAFT, 'remedial' => $run, 'timecreated' => time(), 'timemodified' => time()];
            $job->id = $DB->insert_record('local_quizbot_job', $job);
            $job = job::decoded($job);
            $job->sourcesdata['keys'] = $quiz['material'] !== '' ? [$quiz['material']] : [];
            $job->optionsdata = [
                'counts' => $quiz['counts'] + array_fill_keys(job::TYPES, 0),
                'level' => $level,
                'difficulty' => 'mixed',
                'feedback' => 1,
                'spread' => 0,
                'remedial' => ['topic' => $quiz['topic'], 'students' => array_values(array_map('intval', $quiz['students'])),
                    'extra' => $quiz['extra']],
            ] + job::default_options();
            job::save($job);
            $jobs[] = $job;
        }
        try {
            self::send_waiting($course, $cm, $jobs, true);
        } catch (api_exception $e) {
            foreach ($jobs as $job) {
                self::give_up($job);
            }
            throw $e;
        }
        return $run;
    }

    /**
     * Sends the runs of a go that wait for their turn, one after the other, until Quizbot says it is busy.
     *
     * @param \stdClass $course
     * @param \cm_info $cm the quiz whose analysis it is
     * @param \stdClass[] $jobs the go's runs
     * @param bool $strict throw when Quizbot refuses one (other than busy); otherwise that run gets the reason
     * @throws api_exception
     */
    public static function send_waiting(\stdClass $course, \cm_info $cm, array $jobs, bool $strict = false): void {
        $waiting = array_filter($jobs, fn ($j) => $j->status === job::STATUS_DRAFT);
        if (!$waiting) {
            return;
        }
        $context = \context_module::instance($cm->id);
        $bykey = source_finder::by_key(source_finder::for_course($course, (int) $cm->id));
        foreach ($waiting as $job) {
            try {
                [$remoteid, $names] = sender::send($job, $course, $context, $bykey, $job->optionsdata['remedial']['extra'] ?? []);
            } catch (api_exception $e) {
                if ($e->errorcode === 'busy') {
                    return;
                }
                if ($strict) {
                    throw $e;
                }
                $job->status = job::STATUS_DISCARDED;
                $job->error = $e->getMessage();
                $job->optionsdata['remedial']['students'] = [];
                job::save($job);
                continue;
            }
            $job->status = job::STATUS_RUNNING;
            $job->remoteid = $remoteid;
            $job->result = json_encode(['names' => $names]);
            job::save($job);
        }
    }

    /**
     * The runs of one go, oldest first.
     *
     * @param int $cmid the quiz whose analysis it is
     * @param int $userid the teacher
     * @param string $run the key of the go
     * @return \stdClass[] decoded, as job::decoded()
     */
    public static function jobs(int $cmid, int $userid, string $run): array {
        global $DB;

        if ($run === '') {
            return [];
        }
        $rows = $DB->get_records('local_quizbot_job', ['cmid' => $cmid, 'userid' => $userid, 'remedial' => $run], 'id');
        return array_values(array_map(fn ($row) => job::decoded($row), $rows));
    }

    /**
     * Asks Quizbot how far one run is; takes the questions in when they are written, easy ones first.
     *
     * @param \stdClass $job a run of the go
     * @return array ['state' => done, now, waiting (for its turn) or problem, 'note' => what to tell the teacher]
     */
    public static function poll(\stdClass $job): array {
        if ($job->status === job::STATUS_REVIEW) {
            return ['state' => 'done', 'note' => ''];
        }
        if ($job->status === job::STATUS_DRAFT) {
            return ['state' => 'waiting', 'note' => get_string('remedial_waitingturn', 'local_quizbot')];
        }
        if ($job->status !== job::STATUS_RUNNING) {
            return ['state' => 'problem', 'note' => (string) $job->error];
        }
        try {
            $status = (new api())->status((string) $job->remoteid);
        } catch (api_exception $e) {
            if (
                in_array($e->errorcode, ['not_found', 'licence_missing', 'licence_invalid', 'licence_inactive', 'licence_expired',
                'site_mismatch'], true)
            ) {
                $job->status = job::STATUS_DISCARDED;
                $job->error = $e->getMessage();
                job::save($job);
                return ['state' => 'problem', 'note' => $job->error];
            }
            return ['state' => 'now', 'note' => $e->getMessage()];
        }
        if ($status['status'] === 'done') {
            $notes = [];
            foreach ($status['sources'] ?? [] as $s) {
                if ($s['state'] === 'unreadable') {
                    $notes[] = ['name' => $s['name'], 'note' => $s['note']];
                }
            }
            $job->result = json_encode(['questions' => self::easy_first($status['questions'] ?? []), 'notes' => $notes,
                'names' => job::result($job)['names']]);
            $job->status = job::STATUS_REVIEW;
            job::save($job);
            return ['state' => 'done', 'note' => ''];
        }
        if ($status['status'] === 'failed') {
            $job->status = job::STATUS_DISCARDED;
            $job->error = (string) ($status['error']['message'] ?? get_string('errorunreachable', 'local_quizbot'));
            job::save($job);
            return ['state' => 'problem', 'note' => $job->error];
        }
        return ['state' => 'now', 'note' => ''];
    }

    /**
     * The questions easy first, then medium, then hard; in the order they came within each.
     *
     * @param array $questions
     * @return array
     */
    public static function easy_first(array $questions): array {
        $keyed = [];
        foreach (array_values($questions) as $i => $q) {
            $keyed[] = [self::STEP[$q['difficulty'] ?? ''] ?? 1, $i, $q];
        }
        usort($keyed, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_column($keyed, 2);
    }

    /**
     * Gives a run up: Quizbot may forget what it read, the students chosen are no longer kept.
     *
     * @param \stdClass $job
     */
    public static function give_up(\stdClass $job): void {
        if (!empty($job->remoteid)) {
            try {
                (new api())->saved((string) $job->remoteid, 0);
            } catch (api_exception $e) {
                debugging('Quizbot: could not report the discarded run: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        $job->status = job::STATUS_DISCARDED;
        $job->optionsdata['remedial']['students'] = [];
        job::save($job);
    }

    /**
     * Makes one remedial quiz from a run's ticked questions: a group of its students, the quiz restricted to that
     * group, the questions in it (easy first, each with a hint for a second try). When something fails nothing of it
     * is left in the course.
     *
     * @param \stdClass $course
     * @param \cm_info $source the quiz whose analysis it is
     * @param \stdClass $job a run with its questions reviewed
     * @param array $settings ['name', 'section' => section number, 'behaviour', 'attempts', 'counted' => bool,
     *              'due' => time or 0, 'keep' => quiz or shared]
     * @return \stdClass|null ['cm' => the new quiz, 'added' => questions added, 'students' => int[], 'topic'];
     *              null when no question of the run is ticked
     */
    public static function make(\stdClass $course, \cm_info $source, \stdClass $job, array $settings): ?\stdClass {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/group/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $result = job::result($job);
        $questions = array_values(array_diff_key($result['questions'], array_flip($result['unticked'])));
        $students = $job->optionsdata['remedial']['students'] ?? [];
        $topic = (string) ($job->optionsdata['remedial']['topic'] ?? '');
        if (!$questions || !$students) {
            return null;
        }
        $groupid = self::group($course, get_string('remedial_groupname', 'local_quizbot', $topic), $students);
        $cm = null;
        try {
            $cm = self::quiz($course, $settings, $groupid);
            foreach ($questions as $i => $q) {
                $questions[$i]['hint'] = get_string('remedial_hint', 'local_quizbot');
            }
            $added = question_saver::save($questions, $course, $cm, $settings['keep'], (int) $job->id, $result['names'] ?? []);
        } catch (\Throwable $e) {
            if ($cm) {
                course_delete_module($cm->id);
            }
            groups_delete_group($groupid);
            throw $e;
        }
        if (!empty($job->remoteid)) {
            try {
                // Only what was really added counts on the licence.
                (new api())->saved((string) $job->remoteid, $added);
            } catch (api_exception $e) {
                debugging('Quizbot: could not report the added questions: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        $DB->insert_record('local_quizbot_remedial', (object) [
            'courseid' => $course->id,
            'cmid' => $source->id,
            'quizcmid' => $cm->id,
            'groupid' => $groupid,
            'topic' => \core_text::substr($topic, 0, 255),
            'userid' => $USER->id,
            'timecreated' => time(),
        ]);
        // The group says who the quiz is for from now on: the run no longer keeps the students.
        $job->status = job::STATUS_SAVED;
        $job->optionsdata['remedial']['students'] = [];
        job::save($job);
        return (object) ['cm' => $cm, 'added' => $added, 'students' => $students, 'topic' => $topic];
    }

    /**
     * A group for one remedial quiz, with its students; its name made unique in the course.
     *
     * @param \stdClass $course
     * @param string $name
     * @param int[] $students
     * @return int the group's id
     */
    private static function group(\stdClass $course, string $name, array $students): int {
        $name = \core_text::substr($name, 0, 250);
        $unique = $name;
        for ($n = 2; groups_get_group_by_name($course->id, $unique); $n++) {
            $unique = $name . ' (' . $n . ')';
        }
        $groupid = groups_create_group((object) ['courseid' => $course->id, 'name' => $unique,
            'description' => '', 'descriptionformat' => FORMAT_HTML]);
        foreach ($students as $userid) {
            groups_add_member($groupid, $userid);
        }
        return $groupid;
    }

    /**
     * The quiz itself: in the chosen section, seen only by the group, feedback as chosen, as many attempts as chosen,
     * counted in the course total or not (a quiz with a maximum grade of 0 has no grade).
     *
     * @param \stdClass $course
     * @param array $settings as for make()
     * @param int $groupid
     * @return \cm_info
     */
    private static function quiz(\stdClass $course, array $settings, int $groupid): \cm_info {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');

        $restriction = \core_availability\tree::get_root_json(
            [\availability_group\condition::get_json($groupid)],
            \core_availability\tree::OP_AND,
            false
        );
        $info = [
            'modulename' => 'quiz',
            'course' => $course->id,
            'section' => (int) $settings['section'],
            'visible' => 1,
            'visibleoncoursepage' => 1,
            'name' => $settings['name'],
            'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()],
            'availability' => json_encode($restriction),
            'groupmode' => NOGROUPS,
            'groupingid' => 0,
            'cmidnumber' => '',
            'timeopen' => 0,
            'timeclose' => (int) $settings['due'],
            'timelimit' => 0,
            'overduehandling' => 'autosubmit',
            'graceperiod' => 0,
            'preferredbehaviour' => $settings['behaviour'],
            'canredoquestions' => 0,
            'attempts' => (int) $settings['attempts'],
            'attemptonlast' => 0,
            'grademethod' => QUIZ_GRADEHIGHEST,
            'decimalpoints' => 0,
            'questiondecimalpoints' => -1,
            'questionsperpage' => 1,
            'navmethod' => QUIZ_NAVMETHOD_FREE,
            'shuffleanswers' => 1,
            'sumgrades' => 0,
            'grade' => !empty($settings['counted']) ? 10 : 0,
            'quizpassword' => '',
            'subnet' => '',
            'browsersecurity' => '-',
            'delay1' => 0,
            'delay2' => 0,
            'showuserpicture' => 0,
            'showblocks' => 0,
        ];
        // What the student sees during and after an attempt: everything, so a mistake can be learnt from at once.
        foreach (['during', 'immediately', 'open', 'closed'] as $when) {
            foreach (
                ['attempt', 'correctness', 'maxmarks', 'marks', 'specificfeedback', 'generalfeedback', 'rightanswer',
                    'overallfeedback'] as $what
            ) {
                $info[$what . $when] = ($what === 'overallfeedback' && $when === 'during') ? 0 : 1;
            }
        }
        $made = create_module((object) $info);
        return get_fast_modinfo($course->id)->get_cm($made->coursemodule);
    }

    /**
     * Tells each student of the quizzes made, with a Moodle message from the teacher: the teacher's text, with
     * {firstname} and {topic} filled in, and the link to the student's quiz.
     *
     * @param \stdClass[] $made as make() returns them
     * @param string $text
     * @return int messages sent
     */
    public static function tell(array $made, string $text): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/message/lib.php');

        if (empty($CFG->messaging) || trim($text) === '') {
            return 0;
        }
        $sent = 0;
        foreach ($made as $quiz) {
            foreach ($quiz->students as $userid) {
                $user = \core_user::get_user($userid);
                if (!$user || $user->deleted || !\core_message\api::can_send_message($userid, $USER->id)) {
                    continue;
                }
                $body = str_replace(['{firstname}', '{topic}'], [$user->firstname, $quiz->topic], $text)
                    . "\n\n" . $quiz->cm->get_formatted_name() . ': ' . $quiz->cm->url->out(false);
                if (message_post_message($USER, $user, $body, FORMAT_MOODLE)) {
                    $sent++;
                }
            }
        }
        return $sent;
    }

    /**
     * The remedial quizzes made from a quiz's analysis, and how their students did: who tried, each one's latest
     * finished attempt, and - for those who tried - their result on the topic in the original quiz.
     *
     * @param \cm_info $cm the original quiz
     * @param array $analysis analysis::compute()'s result for the whole class
     * @return array [['record', 'cm' => the remedial quiz, 'members' => int[], 'latest' => user => %, 'tries' => user => n,
     *               'before' => % or null, 'now' => % or null]]
     */
    public static function made(\cm_info $cm, array $analysis): array {
        global $DB;

        $rows = $DB->get_records('local_quizbot_remedial', ['cmid' => $cm->id], 'id');
        $cms = get_fast_modinfo($cm->course)->get_cms();
        $out = [];
        foreach ($rows as $r) {
            if (!isset($cms[$r->quizcmid]) || !empty($cms[$r->quizcmid]->deletioninprogress)) {
                continue;
            }
            $quizcm = $cms[$r->quizcmid];
            $members = array_map('intval', array_keys(groups_get_members($r->groupid, 'u.id')));
            $sumgrades = (float) $DB->get_field('quiz', 'sumgrades', ['id' => $quizcm->instance]);
            $latest = [];
            $tries = [];
            if ($members && $sumgrades > 0) {
                [$in, $params] = $DB->get_in_or_equal($members, SQL_PARAMS_NAMED);
                $attempts = $DB->get_records_select(
                    'quiz_attempts',
                    "quiz = :quiz AND userid $in AND preview = 0 AND state = :finished",
                    ['quiz' => $quizcm->instance, 'finished' => 'finished'] + $params,
                    'timefinish, id',
                    'id, userid, sumgrades'
                );
                foreach ($attempts as $a) {
                    $tries[$a->userid] = ($tries[$a->userid] ?? 0) + 1;
                    $latest[$a->userid] = $a->sumgrades === null ? ($latest[$a->userid] ?? null)
                        : (float) $a->sumgrades / $sumgrades * 100;
                }
            }
            $tried = array_keys(array_filter($latest, fn ($p) => $p !== null));
            $before = [];
            foreach ($tried as $userid) {
                $marks = $analysis['usertopics'][$userid][$r->topic] ?? [];
                if ($marks) {
                    $before[] = array_sum($marks) / count($marks) * 100;
                }
            }
            $now = array_map(fn ($u) => $latest[$u], $tried);
            $out[] = [
                'record' => $r,
                'cm' => $quizcm,
                'members' => $members,
                'latest' => $latest,
                'tries' => $tries,
                'before' => $before ? array_sum($before) / count($before) : null,
                'now' => $now ? array_sum($now) / count($now) : null,
            ];
        }
        return $out;
    }

    /**
     * The remedial quizzes a student has been given in a course and has not finished yet, for "My progress".
     *
     * @param \stdClass $course
     * @param int $userid
     * @return array [['name', 'url', 'topic']]
     */
    public static function for_student(\stdClass $course, int $userid): array {
        global $DB;

        $rows = $DB->get_records_sql('SELECT r.id, r.quizcmid, r.topic
              FROM {local_quizbot_remedial} r
              JOIN {groups_members} gm ON gm.groupid = r.groupid AND gm.userid = :userid
             WHERE r.courseid = :courseid
          ORDER BY r.id', ['userid' => $userid, 'courseid' => $course->id]);
        $cms = get_fast_modinfo($course, $userid)->get_cms();
        $out = [];
        foreach ($rows as $r) {
            $cm = $cms[$r->quizcmid] ?? null;
            if (!$cm || !$cm->uservisible) {
                continue;
            }
            $done = $DB->record_exists('quiz_attempts', ['quiz' => $cm->instance, 'userid' => $userid, 'preview' => 0,
                'state' => 'finished']);
            if (!$done) {
                $out[] = ['name' => $cm->get_formatted_name(), 'url' => $cm->url->out(false), 'topic' => $r->topic];
            }
        }
        return $out;
    }

    /**
     * The remedial quizzes of a course, which the whole-course analysis leaves out: they are practice.
     *
     * @param int $courseid
     * @return int[] their course module ids
     */
    public static function quiz_cmids(int $courseid): array {
        global $DB;

        return array_map('intval', $DB->get_fieldset_select('local_quizbot_remedial', 'quizcmid', 'courseid = ?', [$courseid]));
    }
}
