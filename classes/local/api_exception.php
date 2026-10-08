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
 * Something the Quizbot service refused or could not do. The message is written for the teacher; the code says which
 * kind of problem it is (licence_invalid, limit_reached, file_too_large, unreachable …).
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_exception extends \Exception {
    /** @var string the kind of problem */
    public string $errorcode;

    /**
     * Constructor.
     *
     * @param string $message a sentence the teacher can read
     * @param string $errorcode the kind of problem
     */
    public function __construct(string $message, string $errorcode = 'error') {
        parent::__construct($message);
        $this->errorcode = $errorcode;
    }
}
