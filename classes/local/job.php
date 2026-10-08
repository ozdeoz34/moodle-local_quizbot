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
 * One run of the wizard for one quiz and one teacher: what was chosen so far, kept between the steps.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class job {
    /** @var string still being filled in */
    public const STATUS_DRAFT = 'draft';

    /** @var string sent to Quizbot, which is reading the sources and writing */
    public const STATUS_RUNNING = 'running';

    /** @var string the questions are back and wait for the teacher's review */
    public const STATUS_REVIEW = 'review';

    /** @var string finished: the accepted questions are in the quiz */
    public const STATUS_SAVED = 'saved';

    /** @var string given up by the teacher */
    public const STATUS_DISCARDED = 'discarded';

    /** @var string[] the question types offered, in the order they are shown */
    public const TYPES = ['multichoice', 'truefalse', 'shortanswer', 'numerical', 'match', 'essay'];

    /** @var int the most questions one run may ask for */
    public const MAX_QUESTIONS = 40;

    /**
     * @var array question type => the most of that kind in one run, where that is less than MAX_QUESTIONS. These are
     *            the kinds one source seldom has enough material for (agreed with the product owner, 6 Oct 2026).
     */
    public const MAX_PER_TYPE = ['numerical' => 10, 'match' => 5, 'essay' => 5];

    /** @var int the most sources one run may use: course items, uploaded files, a link and pasted text together */
    public const MAX_SOURCES = 4;

    /**
     * The teacher's unfinished run for this quiz; a new one is started when there is none. Runs for remedial quizzes
     * (remedial.php) are not the wizard's and are left alone.
     *
     * @param int $cmid the quiz's course module id
     * @param int $courseid
     * @param int $userid
     * @return \stdClass the record, with ->sourcesdata and ->optionsdata decoded
     */
    public static function current(int $cmid, int $courseid, int $userid): \stdClass {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal([self::STATUS_DRAFT, self::STATUS_RUNNING, self::STATUS_REVIEW], SQL_PARAMS_NAMED);
        $jobs = $DB->get_records_select(
            'local_quizbot_job',
            "cmid = :cmid AND userid = :userid AND status $insql AND remedial IS NULL",
            ['cmid' => $cmid, 'userid' => $userid] + $params,
            'id DESC',
            '*',
            0,
            1
        );
        $job = reset($jobs);
        if (!$job) {
            $job = (object) [
                'userid' => $userid,
                'courseid' => $courseid,
                'cmid' => $cmid,
                'status' => self::STATUS_DRAFT,
                'sources' => null,
                'options' => null,
                'timecreated' => time(),
                'timemodified' => time(),
            ];
            $job->id = $DB->insert_record('local_quizbot_job', $job);
        }
        return self::decoded($job);
    }

    /**
     * A run's record with what is stored as JSON in it decoded, as ->sourcesdata and ->optionsdata.
     *
     * @param \stdClass $job a local_quizbot_job record
     * @return \stdClass the same record
     */
    public static function decoded(\stdClass $job): \stdClass {
        $job->sourcesdata = self::decode($job->sources ?? null) + ['keys' => [], 'text' => '', 'link' => link_reader::NONE];
        $job->sourcesdata['link'] = (is_array($job->sourcesdata['link']) ? $job->sourcesdata['link'] : []) + link_reader::NONE;
        $job->optionsdata = self::decode($job->options ?? null) + self::default_options();
        return $job;
    }

    /**
     * Stores what the teacher chose.
     *
     * @param \stdClass $job as returned by current()
     */
    public static function save(\stdClass $job): void {
        global $DB;

        $DB->update_record('local_quizbot_job', (object) [
            'id' => $job->id,
            'status' => $job->status,
            'remoteid' => $job->remoteid ?? null,
            'sources' => json_encode($job->sourcesdata),
            'options' => json_encode($job->optionsdata),
            'result' => $job->result ?? null,
            'error' => $job->error ?? null,
            'timemodified' => time(),
        ]);
    }

    /**
     * The questions waiting for review, with the notes about sources that could not be read.
     *
     * @param \stdClass $job
     * @return array ['questions' => [...], 'notes' => [...], 'names' => [source id => name]]
     */
    public static function result(\stdClass $job): array {
        return self::decode($job->result ?? null) + ['questions' => [], 'notes' => [], 'names' => [], 'unticked' => []];
    }

    /**
     * What step 2 starts with.
     *
     * @return array
     */
    public static function default_options(): array {
        return [
            'counts' => ['multichoice' => 5, 'truefalse' => 2, 'shortanswer' => 2, 'numerical' => 1, 'match' => 0, 'essay' => 0],
            'language' => 'auto',
            'level' => 'secondary',
            'difficulty' => 'mixed',
            'feedback' => 1,
            'spread' => 1,
            'keep' => 'quiz',
            // Bloom's levels chosen by the teacher (1.1): off = Quizbot picks the levels and labels every question.
            'bloomon' => 0,
            'skills' => array_fill_keys(bloom::LEVELS, 0),
        ];
    }

    /**
     * How many questions the options ask for in all.
     *
     * @param array $options
     * @return int
     */
    public static function total(array $options): int {
        return (int) array_sum(array_intersect_key($options['counts'] ?? [], array_flip(self::TYPES)));
    }

    /**
     * The languages questions can be written in: 'auto' follows the sources.
     *
     * @return array code => name as shown
     */
    public static function languages(): array {
        return [
            'auto' => get_string('language_auto', 'local_quizbot'),
            'en' => 'English',
            'tr' => 'Türkçe',
            'ar' => 'العربية',
            'es' => 'Español',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'it' => 'Italiano',
            'ru' => 'Русский',
            'hi' => 'हिन्दी',
            'zh' => '中文',
            'ja' => '日本語',
            'ko' => '한국어',
        ];
    }

    /**
     * JSON text to array, tolerant of nothing stored yet.
     *
     * @param string|null $json
     * @return array
     */
    private static function decode(?string $json): array {
        $data = $json ? json_decode($json, true) : null;
        return is_array($data) ? $data : [];
    }
}
