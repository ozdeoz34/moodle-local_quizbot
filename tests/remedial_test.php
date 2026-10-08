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

use local_quizbot\local\job;
use local_quizbot\local\remedial;
use local_quizbot\privacy\provider;

/**
 * Tests for remedial quizzes: who gets which quiz, how many questions of each kind, the material, the order of the
 * questions, and making a quiz seen only by its students - and the privacy of the students chosen.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\local\remedial
 */
final class remedial_test extends \advanced_testcase {
    /**
     * The students below the pass mark by the topic each should revise first, the biggest group first.
     */
    public function test_groups(): void {
        $analysis = [
            'topics' => [['name' => 'Calvin', 'other' => false, 'pct' => 58.0], ['name' => 'Leaf', 'other' => false, 'pct' => 62.0],
                ['name' => 'Other questions', 'other' => true, 'pct' => 50.0]],
            'below' => [['user' => 1, 'score' => 28.0, 'revise' => 'Leaf'], ['user' => 2, 'score' => 38.0, 'revise' => 'Calvin'],
                ['user' => 3, 'score' => 48.0, 'revise' => 'Calvin'], ['user' => 4, 'score' => 50.0, 'revise' => null]],
        ];
        $groups = remedial::groups($analysis);
        $this->assertSame(['Calvin', 'Leaf'], array_column($groups, 'topic'));
        $this->assertSame([2, 3], array_column($groups[0]['students'], 'user'));
        $this->assertSame(58.0, $groups[0]['pct']);
        $this->assertSame(remedial::key('Calvin'), $groups[0]['key']);
    }

    /**
     * The kinds the quiz has, essays out; one of each, then the rest as the quiz shares them out, within limits.
     */
    public function test_kinds_and_counts(): void {
        $questions = array_map(fn ($t) => ['qtype' => $t], ['multichoice', 'multichoice', 'multichoice', 'multichoice',
            'truefalse', 'shortanswer', 'numerical', 'match', 'essay', 'essay', 'random']);
        $kinds = remedial::kinds(['questions' => $questions]);
        $this->assertSame(['multichoice' => 4, 'truefalse' => 1, 'shortanswer' => 1, 'numerical' => 1, 'match' => 1], $kinds);
        $this->assertSame(
            ['multichoice' => 4, 'truefalse' => 1, 'shortanswer' => 1, 'numerical' => 1, 'match' => 1],
            remedial::counts($kinds, 8)
        );
        $this->assertSame(
            ['multichoice' => 1, 'truefalse' => 1, 'shortanswer' => 1],
            remedial::counts($kinds, 3),
            'Fewer questions than kinds: the most used kinds first.'
        );
        $this->assertSame(['numerical' => 10], remedial::counts(['numerical' => 5], 15), 'A kind stays within its limit.');
        $this->assertSame(['multichoice' => 1], remedial::kinds(['questions' => [['qtype' => 'essay']]]));
    }

    /**
     * Easy questions first, then medium, then hard; in the order they came within each.
     */
    public function test_easy_first(): void {
        $q = fn ($text, $difficulty) => ['text' => $text, 'difficulty' => $difficulty];
        $sorted = remedial::easy_first([$q('a', 'hard'), $q('b', 'easy'), $q('c', ''), $q('d', 'easy'), $q('e', 'medium')]);
        $this->assertSame(['b', 'd', 'c', 'e', 'a'], array_column($sorted, 'text'));
    }

    /**
     * A topic's material: the course item its questions remember, else one of the same name; none when it is gone.
     */
    public function test_material(): void {
        $bykey = [
            'q9' => ['name' => 'Calvin notes', 'ref' => ['type' => 'quiz', 'cmid' => 9]],
            't7' => ['name' => 'Calvin notes', 'ref' => ['type' => 'text', 'cmid' => 7]],
            't8' => ['name' => 'Leaf page', 'ref' => ['type' => 'text', 'cmid' => 8]],
        ];
        $q = fn ($source, $cmid) => ['quizbot' => true, 'source' => $source, 'sourcecmid' => $cmid, 'topic' => '', 'text' => 'Q'];
        $this->assertSame('t7', remedial::material([$q('Calvin notes', 7)], 'Calvin notes', $bykey));
        $this->assertSame('t8', remedial::material([$q('Leaf page', 0)], 'Leaf page', $bykey), 'Found by its name.');
        $this->assertSame('', remedial::material([$q('Gone', 0)], 'Gone', $bykey));

        $extra = remedial::extra_sources([$q('Gone', 0), ['text' => 'What is RuBP?'] + $q('Gone', 0)], 'Gone', 'Unit test', false);
        $this->assertSame(['topic', 'quiz'], array_column($extra, 'kind'));
        $this->assertSame("1. Q\n2. What is RuBP?", $extra[1]['text']);
    }

    /**
     * Making a remedial quiz: its group, the restriction to it, the questions easy first with hints (none on true/false),
     * not counted in the grades; the record of it; the run no longer keeps the students. A student of it sees it in
     * "My progress"; the analysis sees who has not started.
     */
    public function test_make(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enableavailability', 1);
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $in = $gen->create_and_enrol($course, 'student');
        $also = $gen->create_and_enrol($course, 'student');
        $out = $gen->create_and_enrol($course, 'student');
        $source = $gen->create_module('quiz', ['course' => $course->id]);
        [$course, $sourcecm] = get_course_and_cm_from_cmid($source->cmid, 'quiz');

        $mc = fn ($text) => ['type' => 'multichoice', 'source' => 't1', 'text' => $text, 'difficulty' => 'easy',
            'topic' => 'Calvin', 'bloom' => 'remember', 'feedback' => '', 'options' => [['text' => 'Stroma', 'correct' => true],
            ['text' => 'Thylakoid', 'correct' => false], ['text' => 'Nucleus', 'correct' => false]]];
        $job = (object) ['userid' => get_admin()->id, 'courseid' => $course->id, 'cmid' => $sourcecm->id,
            'status' => job::STATUS_REVIEW,
            'remedial' => 'abc123', 'timecreated' => time(), 'timemodified' => time()];
        $job->id = $DB->insert_record('local_quizbot_job', $job);
        $job = job::decoded($job);
        $job->optionsdata['remedial'] = ['topic' => 'Calvin', 'students' => [(int) $in->id, (int) $also->id]];
        $job->result = json_encode(['questions' => [$mc('Where is the Calvin cycle?'), ['type' => 'truefalse', 'source' => 't1',
            'text' => 'RuBisCO fixes carbon dioxide.', 'answer' => true, 'difficulty' => 'easy', 'topic' => 'Calvin',
            'bloom' => 'remember', 'feedback' => ''], $mc('Not wanted')], 'names' => ['t1' => 'Calvin notes'], 'notes' => [],
            'unticked' => [2]]);
        job::save($job);

        $made = remedial::make($course, $sourcecm, $job, ['name' => 'Extra practice: Calvin', 'section' => 0,
            'behaviour' => 'interactive', 'attempts' => 0, 'counted' => false, 'due' => 0, 'keep' => 'quiz']);

        $this->assertSame(2, $made->added, 'The unticked question is left out.');
        $quiz = $DB->get_record('quiz', ['id' => $made->cm->instance]);
        $this->assertSame('Extra practice: Calvin', $quiz->name);
        $this->assertSame('interactive', $quiz->preferredbehaviour);
        $this->assertEquals(0, $quiz->grade, 'Not counted in the course total.');
        $record = $DB->get_record('local_quizbot_remedial', ['quizcmid' => $made->cm->id], '*', MUST_EXIST);
        $this->assertEquals($sourcecm->id, $record->cmid);
        $this->assertSame('Calvin', $record->topic);
        $members = array_keys(groups_get_members($record->groupid, 'u.id'));
        $this->assertEqualsCanonicalizing([$in->id, $also->id], $members);
        $this->assertStringContainsString('"id":' . $record->groupid, $DB->get_field(
            'course_modules',
            'availability',
            ['id' => $made->cm->id]
        ));
        $hints = $DB->get_records_sql('SELECT q.qtype, COUNT(h.id) AS n
              FROM {quiz_slots} s
              JOIN {question_references} r ON r.component = ? AND r.questionarea = ? AND r.itemid = s.id
              JOIN {question_versions} v ON v.questionbankentryid = r.questionbankentryid
              JOIN {question} q ON q.id = v.questionid
         LEFT JOIN {question_hints} h ON h.questionid = q.id
             WHERE s.quizid = ?
          GROUP BY q.qtype', ['mod_quiz', 'slot', $quiz->id]);
        $this->assertEquals(1, $hints['multichoice']->n);
        $this->assertEquals(0, $hints['truefalse']->n);
        $saved = job::decoded($DB->get_record('local_quizbot_job', ['id' => $job->id]));
        $this->assertSame(job::STATUS_SAVED, $saved->status);
        $this->assertSame([], $saved->optionsdata['remedial']['students']);

        // The students: theirs to do, nobody else's; the analysis: who has not started.
        $this->assertSame(['Extra practice: Calvin'], array_column(remedial::for_student($course, (int) $in->id), 'name'));
        $this->assertSame([], remedial::for_student($course, (int) $out->id));
        $state = remedial::made($sourcecm, ['usertopics' => []]);
        $this->assertCount(1, $state);
        $this->assertSame([], $state[0]['tries']);
        $this->assertNull($state[0]['now']);
        $this->assertSame([(int) $made->cm->id], remedial::quiz_cmids((int) $course->id));
    }

    /**
     * Privacy: a student chosen for a remedial quiz not made yet is found, exported and taken out.
     */
    public function test_privacy_students(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $quiz = $gen->create_module('quiz', ['course' => $course->id]);
        $context = \context_module::instance($quiz->cmid);
        $DB->insert_record('local_quizbot_job', (object) ['userid' => get_admin()->id, 'courseid' => $course->id,
            'cmid' => $quiz->cmid, 'status' => job::STATUS_REVIEW, 'remedial' => 'abc123', 'timecreated' => time(),
            'timemodified' => time(), 'options' => json_encode(['remedial' => ['topic' => 'Calvin',
            'students' => [(int) $student->id, 99]]])]);

        $this->assertTrue(in_array($context->id, provider::get_contexts_for_userid($student->id)->get_contextids()));
        $userlist = new \core_privacy\local\request\userlist($context, 'local_quizbot');
        provider::get_users_in_context($userlist);
        $this->assertTrue(in_array($student->id, $userlist->get_userids()));

        provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            $student,
            'local_quizbot',
            [$context->id]
        ));
        $options = json_decode($DB->get_field('local_quizbot_job', 'options', ['cmid' => $quiz->cmid]), true);
        $this->assertSame([99], $options['remedial']['students'], 'Only that student is taken out.');
        $this->assertFalse(in_array($context->id, provider::get_contexts_for_userid($student->id)->get_contextids()));
    }
}
