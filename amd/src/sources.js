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
 * Step 1 of the wizard: keeps the count on the "From this course" tab and the "sources chosen" panel in step with
 * the boxes the teacher ticks. The page works without this script too - then the numbers change on the next page.
 *
 * @module     local_quizbot/sources
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Start listening to the tick boxes.
 */
export const init = () => {
    const form = document.getElementById('local-quizbot-sources');
    const list = document.getElementById('local-quizbot-chosen');
    const title = document.getElementById('local-quizbot-chosentitle');
    if (!form || !list || !title) {
        return;
    }
    const badge = document.getElementById('local-quizbot-count-course');
    const note = document.getElementById('local-quizbot-maxnote');
    const max = parseInt(form.getAttribute('data-max'), 10) || 0;

    const update = () => {
        const checked = Array.prototype.filter.call(form.querySelectorAll('input[name="sources[]"]'), (box) => box.checked);
        Array.prototype.forEach.call(list.querySelectorAll('li[data-key]'), (item) => item.parentNode.removeChild(item));
        const first = list.querySelector('li[data-fixed]');
        checked.forEach((box) => {
            const label = box.closest('label');
            const item = document.createElement('li');
            item.className = 'local-quizbot-note p-2 mb-2';
            item.setAttribute('data-key', box.value);
            ['name', 'detail'].forEach((role) => {
                const from = label ? label.querySelector('[data-role="' + role + '"]') : null;
                if (from) {
                    const line = document.createElement('span');
                    line.className = role === 'name' ? 'd-block' : 'd-block small text-muted';
                    line.textContent = from.textContent;
                    line.dir = 'auto';
                    item.appendChild(line);
                }
            });
            list.insertBefore(item, first);
        });
        const total = checked.length + list.querySelectorAll('li[data-fixed]').length;
        if (total === 0) {
            title.textContent = title.getAttribute('data-none');
        } else if (total === 1) {
            title.textContent = title.getAttribute('data-one');
        } else {
            title.textContent = title.getAttribute('data-many').replace('{n}', total);
        }
        if (badge) {
            badge.textContent = checked.length;
            badge.hidden = checked.length === 0;
        }
        // At the limit, the boxes not yet ticked cannot be ticked until one is given up.
        const full = max > 0 && total >= max;
        Array.prototype.forEach.call(form.querySelectorAll('input[name="sources[]"]'), (box) => {
            box.disabled = full && !box.checked;
            const label = box.closest('label');
            if (label) {
                label.classList.toggle('text-muted', box.disabled);
            }
        });
        if (note) {
            note.classList.toggle('fw-bold', full);
            note.classList.toggle('font-weight-bold', full);       // The same in Bootstrap 4 (Moodle 4.x).
        }
    };

    form.addEventListener('change', (event) => {
        if (event.target && event.target.name === 'sources[]') {
            update();
        }
    });
    update();
};
