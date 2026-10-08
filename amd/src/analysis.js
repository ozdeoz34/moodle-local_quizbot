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

/**
 * Quizbot Analysis: "Send them a message" (the students below the pass mark) and "Remind …" (the students who have not
 * started their remedial quiz) open Moodle's own message window, the one of the course's Participants page. Moodle
 * sends the message and checks who may receive it.
 *
 * @module     local_quizbot/analysis
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {showModal} from 'core_message/message_send_bulk';

/**
 * Start listening.
 */
export const init = () => {
    document.querySelectorAll('.local-quizbot-message[data-users]').forEach((button) => {
        button.addEventListener('click', () => {
            showModal(JSON.parse(button.getAttribute('data-users') || '[]'));
        });
    });
};
