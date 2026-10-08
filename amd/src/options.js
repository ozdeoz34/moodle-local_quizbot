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
 * Step 2 of the wizard: adds up the numbers the teacher types and shows at once when the total passes the most one
 * run may ask for. The page works without this script too - then the total is checked when "Generate" is pressed.
 *
 * @module     local_quizbot/options
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Start listening to the number boxes.
 */
export const init = () => {
    const form = document.getElementById('local-quizbot-options');
    const total = document.getElementById('local-quizbot-total');
    const note = document.getElementById('local-quizbot-totalnote');
    if (!form || !total || !note) {
        return;
    }
    const max = parseInt(note.getAttribute('data-max'), 10) || 0;

    const update = () => {
        let sum = 0;
        Array.prototype.forEach.call(form.querySelectorAll('input[type="number"][name^="n_"]'), (box) => {
            const n = parseInt(box.value, 10);
            sum += n > 0 ? n : 0;
            // A kind with a limit of its own: the box and its "up to ..." line turn red as soon as it is passed.
            const line = form.querySelector('[data-limit-for="' + box.id + '"]');
            if (line) {
                const toomany = n > (parseInt(box.getAttribute('max'), 10) || 0);
                box.classList.toggle('is-invalid', toomany);
                line.classList.toggle('text-danger', toomany);
                line.classList.toggle('fw-bold', toomany);
                line.classList.toggle('font-weight-bold', toomany);
                line.classList.toggle('text-muted', !toomany);
            }
        });
        total.textContent = sum;
        const over = max > 0 && sum > max;
        total.classList.toggle('text-danger', over);
        note.hidden = !over;
    };

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
};
