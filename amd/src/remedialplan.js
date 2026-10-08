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
 * Make remedial quizzes, step 1: the summary at the foot ("Quizzes to write: 4 · questions: 32") follows every tick,
 * every move to another topic and every number of questions. Without script it shows the numbers the page began with,
 * and the page itself counts again when the questions are written.
 *
 * @module     local_quizbot/remedialplan
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Start following the form.
 */
export const init = () => {
    const form = document.getElementById('local-quizbot-remedialplan');
    const summary = document.getElementById('local-quizbot-remedialsummary');
    if (!form || !summary) {
        return;
    }
    const template = summary.getAttribute('data-template') || '';
    const update = () => {
        // The quizzes that will be written: the topics that keep at least one student, moves included.
        const topics = new Set();
        form.querySelectorAll('input[name="s[]"]').forEach((box) => {
            if (!box.checked) {
                return;
            }
            const move = form.querySelector('select[name="m[' + box.value + ']"]');
            topics.add(move && move.value ? move.value : box.getAttribute('data-group'));
        });
        let questions = 0;
        topics.forEach((key) => {
            const number = form.querySelector('input[name="n[' + key + ']"]');
            questions += number ? Math.max(0, parseInt(number.value, 10) || 0) : 0;
        });
        summary.textContent = template.replace('{quizzes}', String(topics.size)).replace('{questions}', String(questions));
    };
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
};
