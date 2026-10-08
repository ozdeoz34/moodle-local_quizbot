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

require_once($CFG->libdir . '/filelib.php');

/**
 * Talks to the Quizbot service. Every call is made by this site's server (never by a browser) with the school's
 * licence key; what is sent is the material the teacher chose and the writing options - no student data.
 *
 * Each method returns the decoded answer, or throws api_exception with a message the teacher can read.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** @var string the service's address, without a trailing slash */
    private string $base;

    /** @var string the school's licence key */
    private string $key;

    /**
     * Reads the administrator's settings.
     */
    public function __construct() {
        $this->base = rtrim((string) get_config('local_quizbot', 'serverurl') ?: 'https://quizbot.ai', '/') . '/api/moodle/v1';
        $this->key = trim((string) get_config('local_quizbot', 'licencekey'));
    }

    /**
     * Is a licence key entered at all?
     *
     * @return bool
     */
    public function has_key(): bool {
        return $this->key !== '';
    }

    /**
     * The licence: plan, questions left, limits.
     *
     * @return array
     */
    public function verify(): array {
        return $this->call('post', '/verify');
    }

    /**
     * Starts a request.
     *
     * @param array $options the writing options
     * @param array $sources the list of sources (texts inside; files follow with add_file())
     * @return array with 'job' (its id) and 'upload' (ids of the sources whose file is expected)
     */
    public function create(array $options, array $sources): array {
        return $this->call('post', '/jobs', ['options' => $options, 'sources' => $sources]);
    }

    /**
     * Sends one file of a request.
     *
     * @param string $job
     * @param string $sourceid
     * @param \stored_file $file
     * @return array
     */
    public function add_file(string $job, string $sourceid, \stored_file $file): array {
        return $this->call('upload', '/jobs/' . $job . '/files', ['source_id' => $sourceid, 'file' => $file]);
    }

    /**
     * All files are in: begin.
     *
     * @param string $job
     * @return array
     */
    public function start(string $job): array {
        return $this->call('post', '/jobs/' . $job . '/start');
    }

    /**
     * How far a request is; the questions when it is done.
     *
     * @param string $job
     * @return array
     */
    public function status(string $job): array {
        return $this->call('get', '/jobs/' . $job);
    }

    /**
     * Tells the service how many questions the teacher added to the quiz - the number that counts on the licence.
     *
     * @param string $job
     * @param int $count
     * @return array
     */
    public function saved(string $job, int $count): array {
        return $this->call('post', '/jobs/' . $job . '/saved', ['count' => $count]);
    }

    /**
     * Has one question of a finished request written anew.
     *
     * @param string $job
     * @param int $index the question's place in the request's list
     * @param string[] $avoid the questions the teacher has on the review page now, so the new one is different
     * @param string $bloom the Bloom's level the new question should test ('' = the one Quizbot gave the old question)
     * @return array the answer; ['question'] is the new question
     */
    public function rewrite(string $job, int $index, array $avoid, string $bloom = ''): array {
        $data = ['index' => $index, 'avoid' => array_values($avoid)];
        if ($bloom !== '') {
            $data['bloom'] = $bloom;
        }
        return $this->call('post', '/jobs/' . $job . '/rewrite', $data);
    }

    /**
     * A code that stands for one teacher of this site. A licence is for a number of teachers, so the service has to
     * tell "the same teacher again" from "another teacher" - and nothing more: the code is a one-way hash of this
     * site's own secret identifier and the user's number, so it says nothing about the person and cannot be turned
     * back into a user, not even by the service.
     *
     * @param int $userid
     * @return string 64 hexadecimal characters
     */
    public static function teacher_code(int $userid): string {
        return hash('sha256', get_site_identifier() . ':quizbot-teacher:' . $userid);
    }

    /**
     * One request to the service.
     *
     * @param string $how get, post (JSON body) or upload (form with a file)
     * @param string $path
     * @param array|null $data
     * @return array the decoded answer
     * @throws api_exception
     */
    private function call(string $how, string $path, ?array $data = null): array {
        global $CFG, $USER;

        if ($this->key === '') {
            throw new api_exception(get_string('errornokey', 'local_quizbot'), 'licence_missing');
        }
        $curl = new \curl();
        $headers = [
            'Authorization: Bearer ' . $this->key,
            'X-Moodle-Site: ' . $CFG->wwwroot,
            'Accept: application/json',
        ];
        if (!empty($USER->id) && !isguestuser()) {
            $headers[] = 'X-Moodle-Teacher: ' . self::teacher_code((int) $USER->id);
        }
        $options = ['CURLOPT_CONNECTTIMEOUT' => 15, 'CURLOPT_TIMEOUT' => $how === 'upload' ? 300 : 60];
        if ($how === 'get') {
            $curl->setHeader($headers);
            $raw = $curl->get($this->base . $path, [], $options);
        } else if ($how === 'upload') {
            $curl->setHeader($headers);
            $raw = $curl->post($this->base . $path, $data, $options);
        } else {
            $headers[] = 'Content-Type: application/json';
            $curl->setHeader($headers);
            $raw = $curl->post($this->base . $path, json_encode($data ?? new \stdClass()), $options);
        }

        $answer = json_decode((string) $raw, true);
        if ($curl->get_errno() || !is_array($answer)) {
            // No connection, a time-out, or something in between answered instead of Quizbot.
            debugging(
                'Quizbot: ' . $how . ' ' . $path . ' failed: ' . ($curl->error ?: 'HTTP ' . ($curl->get_info()['http_code']
                    ?? '?')),
                DEBUG_DEVELOPER
            );
            throw new api_exception(get_string('errorunreachable', 'local_quizbot'), 'unreachable');
        }
        if (empty($answer['ok'])) {
            throw new api_exception(
                (string) ($answer['error']['message'] ?? get_string('errorunreachable', 'local_quizbot')),
                (string) ($answer['error']['code'] ?? 'error')
            );
        }
        return $answer;
    }
}
