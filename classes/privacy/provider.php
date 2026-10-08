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

namespace local_quizbot\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy: what the plugin keeps about a person (a teacher's wizard runs, and who added which question with Quizbot)
 * and what leaves the site.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describes the data kept and the data sent away.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_quizbot_job', [
            'userid' => 'privacy:metadata:local_quizbot_job:userid',
            'cmid' => 'privacy:metadata:local_quizbot_job:cmid',
            'sources' => 'privacy:metadata:local_quizbot_job:sources',
            'options' => 'privacy:metadata:local_quizbot_job:options',
            'result' => 'privacy:metadata:local_quizbot_job:result',
            'timecreated' => 'privacy:metadata:local_quizbot_job:timecreated',
        ], 'privacy:metadata:local_quizbot_job');
        $collection->add_database_table('local_quizbot_question', [
            'userid' => 'privacy:metadata:local_quizbot_question:userid',
            'questionbankentryid' => 'privacy:metadata:local_quizbot_question:questionbankentryid',
            'sourcename' => 'privacy:metadata:local_quizbot_question:sourcename',
            'timecreated' => 'privacy:metadata:local_quizbot_question:timecreated',
        ], 'privacy:metadata:local_quizbot_question');
        $collection->add_database_table('local_quizbot_remedial', [
            'userid' => 'privacy:metadata:local_quizbot_remedial:userid',
            'quizcmid' => 'privacy:metadata:local_quizbot_remedial:quizcmid',
            'topic' => 'privacy:metadata:local_quizbot_remedial:topic',
            'timecreated' => 'privacy:metadata:local_quizbot_remedial:timecreated',
        ], 'privacy:metadata:local_quizbot_remedial');
        $collection->add_external_location_link('quizbotserver', [
            'material' => 'privacy:metadata:quizbotserver:material',
            'options' => 'privacy:metadata:quizbotserver:options',
            'teacher' => 'privacy:metadata:quizbotserver:teacher',
        ], 'privacy:metadata:quizbotserver');
        // Files a teacher uploads for a run, or that are fetched from a link, are kept with Moodle's file system
        // until the run ends.
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');
        return $collection;
    }

    /**
     * The quizzes a user ran the wizard for, and the courses where they added questions with it.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_quizbot_job} j
               JOIN {context} ctx ON ctx.instanceid = j.cmid AND ctx.contextlevel = :level
              WHERE j.userid = :userid",
            ['level' => CONTEXT_MODULE, 'userid' => $userid]
        );
        // The questions outlive the quiz they were written for (they may sit in the course's bank): by course.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_quizbot_question} q
               JOIN {context} ctx ON ctx.instanceid = q.courseid AND ctx.contextlevel = :level
              WHERE q.userid = :userid",
            ['level' => CONTEXT_COURSE, 'userid' => $userid]
        );
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {local_quizbot_remedial} r
               JOIN {context} ctx ON ctx.instanceid = r.courseid AND ctx.contextlevel = :level
              WHERE r.userid = :userid",
            ['level' => CONTEXT_COURSE, 'userid' => $userid]
        );
        // A student chosen for a remedial quiz that is not made yet: by the quiz whose analysis it came from.
        $cmids = array_unique(array_column(array_filter(self::remedial_students(), fn ($r) => in_array(
            $userid,
            $r['students'],
            true
        )), 'cmid'));
        if ($cmids) {
            [$in, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
            $contextlist->add_from_sql(
                "SELECT id FROM {context} WHERE contextlevel = :level AND instanceid $in",
                ['level' => CONTEXT_MODULE] + $params
            );
        }
        return $contextlist;
    }

    /**
     * The students chosen for remedial quizzes that are not made yet (the runs keep them until then).
     *
     * @param int $cmid only the runs from this quiz's analysis; 0 = all
     * @return array job id => ['cmid', 'topic', 'students' => int[]]
     */
    private static function remedial_students(int $cmid = 0): array {
        global $DB;

        $where = 'remedial IS NOT NULL' . ($cmid ? ' AND cmid = :cmid' : '');
        $out = [];
        $rows = $DB->get_records_select('local_quizbot_job', $where, $cmid ? ['cmid' => $cmid] : [], 'id', 'id, cmid, options');
        foreach ($rows as $job) {
            $remedial = json_decode((string) $job->options, true)['remedial'] ?? [];
            if (!empty($remedial['students'])) {
                $out[$job->id] = ['cmid' => (int) $job->cmid, 'topic' => (string) ($remedial['topic'] ?? ''),
                    'students' => array_map('intval', $remedial['students'])];
            }
        }
        return $out;
    }

    /**
     * Takes students out of the remedial quizzes chosen for them that are not made yet.
     *
     * @param int $cmid the quiz whose analysis they came from
     * @param int[] $userids
     */
    private static function forget_students(int $cmid, array $userids): void {
        global $DB;

        foreach (self::remedial_students($cmid) as $jobid => $r) {
            if (!array_intersect($r['students'], $userids)) {
                continue;
            }
            $options = json_decode((string) $DB->get_field('local_quizbot_job', 'options', ['id' => $jobid]), true) ?: [];
            $options['remedial']['students'] = array_values(array_diff($r['students'], $userids));
            $DB->set_field('local_quizbot_job', 'options', json_encode($options), ['id' => $jobid]);
        }
    }

    /**
     * The users who ran the wizard for the quiz in a context, or added questions with it in a course.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_COURSE) {
            $userlist->add_from_sql(
                'userid',
                'SELECT userid FROM {local_quizbot_question} WHERE courseid = :courseid AND userid > 0',
                ['courseid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'userid',
                'SELECT userid FROM {local_quizbot_remedial} WHERE courseid = :courseid AND userid > 0',
                ['courseid' => $context->instanceid]
            );
            return;
        }
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {local_quizbot_job} WHERE cmid = :cmid',
            ['cmid' => $context->instanceid]
        );
        foreach (self::remedial_students((int) $context->instanceid) as $r) {
            $userlist->add_users($r['students']);
        }
    }

    /**
     * Exports a user's wizard runs, and the questions they added with Quizbot.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                $rows = $DB->get_records('local_quizbot_question', ['courseid' => $context->instanceid, 'userid' => $userid], 'id');
                if ($rows) {
                    $export = [];
                    foreach ($rows as $row) {
                        $export[] = (object) [
                            'questionbankentryid' => $row->questionbankentryid,
                            'type' => $row->qtype,
                            'source' => $row->sourcename,
                            'topic' => $row->topic,
                            'bloom' => $row->bloom,
                            'difficulty' => $row->difficulty,
                            'timecreated' => \core_privacy\local\request\transform::datetime($row->timecreated),
                        ];
                    }
                    writer::with_context($context)->export_data(
                        [get_string('privacy:pathquestions', 'local_quizbot')],
                        (object) ['questions' => $export]
                    );
                }
                $rows = $DB->get_records('local_quizbot_remedial', ['courseid' => $context->instanceid, 'userid' => $userid], 'id');
                if ($rows) {
                    writer::with_context($context)->export_data(
                        [get_string('privacy:pathremedial', 'local_quizbot')],
                        (object) ['remedialquizzes' => array_values(array_map(
                            fn ($r) => (object) ['quizcmid' => $r->quizcmid, 'topic' => $r->topic,
                                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated)],
                            $rows
                        ))]
                    );
                }
                continue;
            }
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            $chosen = array_filter(self::remedial_students((int) $context->instanceid), fn ($r) => in_array(
                (int) $userid,
                $r['students'],
                true
            ));
            if ($chosen) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:pathchosen', 'local_quizbot')],
                    (object) ['topics' => array_values(array_column($chosen, 'topic'))]
                );
            }
            $jobs = $DB->get_records('local_quizbot_job', ['cmid' => $context->instanceid, 'userid' => $userid], 'id');
            if (!$jobs) {
                continue;
            }
            $export = [];
            foreach ($jobs as $job) {
                $export[] = (object) [
                    'status' => $job->status,
                    'sources' => $job->sources,
                    'options' => $job->options,
                    'result' => $job->result,
                    'timecreated' => \core_privacy\local\request\transform::datetime($job->timecreated),
                ];
                foreach (['upload', 'link'] as $area) {
                    writer::with_context($context)->export_area_files(
                        [get_string('privacy:path', 'local_quizbot')],
                        'local_quizbot',
                        $area,
                        $job->id
                    );
                }
            }
            writer::with_context($context)->export_data(
                [get_string('privacy:path', 'local_quizbot')],
                (object) ['runs' => $export]
            );
        }
    }

    /**
     * Deletes every wizard run for the quiz in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel == CONTEXT_COURSE) {
            self::forget_teachers('courseid = :courseid', ['courseid' => $context->instanceid]);
            return;
        }
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $jobs = $DB->get_records('local_quizbot_job', ['cmid' => $context->instanceid], '', 'id');
        self::delete_jobs($context, array_keys($jobs));
    }

    /**
     * Deletes one user's wizard runs in the given contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel == CONTEXT_COURSE) {
                self::forget_teachers('courseid = :courseid AND userid = :userid', ['courseid' => $context->instanceid,
                    'userid' => $userid]);
                continue;
            }
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            $jobs = $DB->get_records('local_quizbot_job', ['cmid' => $context->instanceid, 'userid' => $userid], '', 'id');
            self::delete_jobs($context, array_keys($jobs));
            self::forget_students((int) $context->instanceid, [(int) $userid]);
        }
    }

    /**
     * Deletes several users' wizard runs for the quiz in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$userlist->get_userids()) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        if ($context->contextlevel == CONTEXT_COURSE) {
            self::forget_teachers("courseid = :courseid AND userid $insql", ['courseid' => $context->instanceid] + $params);
            return;
        }
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $jobs = $DB->get_records_select(
            'local_quizbot_job',
            "cmid = :cmid AND userid $insql",
            ['cmid' => $context->instanceid] + $params,
            '',
            'id'
        );
        self::delete_jobs($context, array_keys($jobs));
        self::forget_students((int) $context->instanceid, array_map('intval', $userlist->get_userids()));
    }

    /**
     * Removes wizard runs together with the files uploaded for them.
     *
     * @param \context $context the quiz's module context
     * @param int[] $jobids
     */
    private static function delete_jobs(\context $context, array $jobids): void {
        global $DB;

        $fs = get_file_storage();
        foreach ($jobids as $jobid) {
            $fs->delete_area_files($context->id, 'local_quizbot', 'upload', $jobid);
            $fs->delete_area_files($context->id, 'local_quizbot', 'link', $jobid);
        }
        if ($jobids) {
            $DB->delete_records_list('local_quizbot_job', 'id', $jobids);
        }
    }

    /**
     * Takes the teacher out of the record of questions added with Quizbot. The questions stay in the question bank
     * (Moodle's own privacy code looks after them) and keep their topic, Bloom's level and difficulty; who added them
     * and the name of the material they came from (a file the teacher uploaded may carry a name) are removed.
     *
     * @param string $where SQL condition on local_quizbot_question
     * @param array $params
     */
    private static function forget_teachers(string $where, array $params): void {
        global $DB;

        $DB->execute("UPDATE {local_quizbot_question} SET userid = 0, sourcename = '' WHERE $where", $params);
        // The record of remedial quizzes keeps the quiz and its topic; who made it goes.
        $DB->execute("UPDATE {local_quizbot_remedial} SET userid = 0 WHERE $where", $params);
    }
}
