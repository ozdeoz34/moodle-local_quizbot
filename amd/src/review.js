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
 * The review page: while a question is rewritten (a few seconds) or the questions are added, the pressed button says
 * so and turns a small wheel, and no button can be pressed twice. The page works without this script too.
 *
 * @module     local_quizbot/review
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SPINNER = '<svg width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" focusable="false" '
    + 'style="vertical-align: -1px; margin-inline-end: .4em"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" '
    + 'stroke-opacity=".35" stroke-width="4"/><path d="M12 3a9 9 0 0 1 9 9" fill="none" stroke="currentColor" stroke-width="4" '
    + 'stroke-linecap="round"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" '
    + 'dur="0.9s" repeatCount="indefinite"/></path></svg>';

/**
 * The buttons that show only the questions of one Bloom's level (1.1). Hidden questions keep their tick and are still
 * sent with the form.
 */
export const filter = () => {
    const chips = document.getElementById('local-quizbot-skillchips');
    if (!chips) {
        return;
    }
    chips.hidden = false;
    chips.addEventListener('click', (event) => {
        const chip = event.target.closest('button[data-skill]');
        if (!chip) {
            return;
        }
        const skill = chip.getAttribute('data-skill');
        Array.prototype.forEach.call(chips.querySelectorAll('button[data-skill]'), (b) => {
            const on = b === chip;
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.classList.toggle('btn-dark', on);
            b.classList.toggle('btn-outline-secondary', !on);
        });
        Array.prototype.forEach.call(document.querySelectorAll('#local-quizbot-review .card[data-skill]'), (card) => {
            card.hidden = skill !== '' && card.getAttribute('data-skill') !== skill;
        });
    });
};

/**
 * Start listening to the review form.
 */
export const init = () => {
    const form = document.getElementById('local-quizbot-review');
    if (!form) {
        return;
    }
    let busy = false;
    form.addEventListener('submit', (event) => {
        const button = event.submitter;
        if (busy) {
            event.preventDefault();
            return;
        }
        if (!button || !button.getAttribute('data-busy')) {
            return;
        }
        busy = true;
        // The pressed button's value must still be sent once the buttons are switched off.
        const action = document.createElement('input');
        action.type = 'hidden';
        action.name = button.name;
        action.value = button.value;
        form.appendChild(action);
        const label = document.createElement('span');
        label.textContent = button.getAttribute('data-busy');
        button.innerHTML = SPINNER;
        button.appendChild(label);
        setTimeout(() => {
            Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"]'), (b) => {
                b.disabled = true;
            });
        }, 0);
    });
};
