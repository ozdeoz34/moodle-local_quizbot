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
 * The result card on a quiz attempt's review page is written at the foot of the page (the only place Moodle gives a
 * plugin there); this moves it to the top, just before Moodle's summary of the attempt. Without script it stays at
 * the foot, still readable.
 *
 * @module     local_quizbot/card
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Move the card.
 */
export const init = () => {
    const card = document.getElementById('local-quizbot-card');
    if (!card) {
        return;
    }
    const summary = document.querySelector('table.quizreviewsummary');
    const main = document.querySelector('#region-main [role="main"]') || document.querySelector('[role="main"]');
    if (summary && summary.parentNode) {
        summary.parentNode.insertBefore(card, summary);
    } else if (main) {
        main.insertBefore(card, main.firstChild);
    }
};
