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
 * Puts the plugin's items on the line of tabs instead of under "More": "Quizbot Analysis" beside "Question bank" on a
 * quiz, "My progress" beside "Competencies" on a course (and on a quiz, for its students). Moodle marks every plugin's
 * item to stay under "More". This lifts that mark from our items only; Moodle's own menu script then moves them onto
 * the line, and back under "More" when the screen is too narrow, as it does with its own items. Themes without that
 * menu are left as they are.
 *
 * @module     local_quizbot/navtab
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Lift the mark and let Moodle's menu script lay the line out again.
 */
export const init = () => {
    // Not "Generate with Quizbot": its own button is on the quiz's pages, and the line has room for few items.
    const items = document.querySelectorAll('li[data-key="local_quizbot_analysis"][data-forceintomoremenu="true"],'
        + ' li[data-key="local_quizbot_myprogress"][data-forceintomoremenu="true"]');
    if (!items.length) {
        return;
    }
    items.forEach((item) => {
        item.dataset.forceintomoremenu = 'false';
    });
    window.dispatchEvent(new Event('resize'));
};
