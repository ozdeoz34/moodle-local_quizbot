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
 * The quiz's Questions page, for a quiz with Quizbot questions: when the questions' marks add up to a different number
 * than the quiz's maximum grade (11 marks, graded out of 10), "Total of marks" is marked and a line says what Moodle
 * does with it - it scales every student's marks to the maximum grade - with a button that sets the maximum grade to
 * the total and saves it with Moodle's own form. It follows Moodle's page as marks are changed or questions removed.
 *
 * @module     local_quizbot/marks
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * A number as Moodle shows it (11.00, or 11,00 in some languages).
 *
 * @param {string} text
 * @returns {number}
 */
const number = (text) => parseFloat(String(text).trim().replace(/\s/g, '').replace(',', '.'));

/**
 * Start watching the marks.
 *
 * @param {object} strings {note: with {marks} and {grade}, button: with {marks}}
 */
export const init = (strings) => {
    const total = document.querySelector('.totalpoints .mod_quiz_summarks');
    const input = document.getElementById('inputmaxgrade');
    if (!total || !input || !input.form) {
        return;
    }
    const holder = total.closest('.totalpoints');
    const row = holder.closest('.mod_quiz-edit-top-controls > div') || holder.parentElement;
    const note = document.createElement('div');
    note.className = 'local-quizbot-marksnote';
    note.setAttribute('role', 'status');
    note.hidden = true;
    row.insertAdjacentElement('afterend', note);

    const check = () => {
        const marks = number(total.textContent);
        // The maximum grade as saved, not what is being typed into the box.
        const grade = number(input.defaultValue);
        const differ = marks > 0 && grade > 0 && Math.abs(marks - grade) > 0.005;
        holder.classList.toggle('local-quizbot-marksdiffer', differ);
        note.hidden = !differ;
        note.textContent = '';
        if (!differ) {
            return;
        }
        const text = document.createElement('span');
        text.textContent = strings.note.split('{marks}').join(String(marks)).split('{grade}').join(String(grade));
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-outline-danger ms-2 ml-2';
        button.textContent = strings.button.split('{marks}').join(String(marks));
        button.addEventListener('click', () => {
            input.value = total.textContent.trim();
            const save = input.form.querySelector('[type="submit"]');
            if (save) {
                save.click();
            }
        });
        note.append(text, button);
    };
    new MutationObserver(check).observe(total, {childList: true, characterData: true, subtree: true});
    check();
};
