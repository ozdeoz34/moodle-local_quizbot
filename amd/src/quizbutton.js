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
 * Puts "Generate with Quizbot" on a quiz's two teacher pages, at the right-hand end of the page's row of controls,
 * where Moodle offers plugins no place of their own. ("Quizbot Analysis" has its own tab on the quiz's line of tabs,
 * see local_quizbot/navtab.)
 *
 * It never relies on one theme's layout: each place is found by what the element IS (a link to the quiz's edit
 * page, the row of the page's own controls, the page's main region), not by a theme's class names.
 *
 * On the quiz's main page:
 *   1. after Moodle's own "Add question" button;
 *   2. at the end of the quiz's button row, when the quiz already has questions;
 *   3. in a row of its own at the top of the page content.
 * On the quiz's Questions page:
 *   1. at the end of the row that holds the page's "Questions" selector;
 *   2. in a row of its own at the top of the page content.
 * If no place is found nothing is added - the entry in the quiz's navigation is always there.
 *
 * @module     local_quizbot/quizbutton
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const ID = 'local-quizbot-generate';

/**
 * Add the button.
 *
 * @param {Object} config
 * @param {String} config.url where the button goes
 * @param {String} config.label its text
 * @param {String} config.icon address of the Quizbot head shown before the text (optional)
 * @param {String} config.page 'view' for the quiz's main page, 'edit' for its Questions page
 */
export const init = (config) => {
    if (document.getElementById(ID)) {
        return;
    }
    const link = document.createElement('a');
    link.id = ID;
    link.href = config.url;
    link.className = 'btn btn-primary local-quizbot-cta';
    if (config.icon) {
        // The Quizbot head, on a white disc so it reads on the button's colour whatever the theme makes it.
        const head = document.createElement('img');
        head.src = config.icon;
        head.alt = '';
        head.width = 22;
        head.height = 22;
        head.style.cssText = 'background:#fff;border-radius:50%;padding:1px;margin-inline-end:.45rem;vertical-align:-5px';
        link.appendChild(head);
    }
    link.appendChild(document.createTextNode(config.label));
    const item = document.createElement('div');
    // At the right-hand end of the row (ms-auto / ml-auto: both Bootstrap spellings).
    item.className = 'navitem ms-auto ml-auto';
    item.appendChild(link);

    const main = document.querySelector('#region-main [role="main"]') ||
        document.querySelector('[role="main"]') ||
        document.getElementById('region-main');
    const row = document.querySelector('.tertiary-navigation .d-flex') || document.querySelector('.tertiary-navigation');

    const ownrow = () => {
        if (!main) {
            return;
        }
        const own = document.createElement('div');
        own.className = 'tertiary-navigation d-flex mb-3 justify-content-end';
        own.appendChild(item);
        main.insertBefore(own, main.firstChild);
        link.dataset.place = 'own-row';
    };

    if (config.page === 'edit') {
        if (row) {
            row.appendChild(item);
            link.dataset.place = 'questions-page-row';
            return;
        }
        ownrow();
        return;
    }

    const add = document.querySelector('.tertiary-navigation a[href*="/mod/quiz/edit.php"]') ||
        (main ? main.querySelector('a.btn[href*="/mod/quiz/edit.php"]') : null);
    if (add) {
        const anchor = add.closest('.navitem') || add;
        anchor.parentNode.insertBefore(item, anchor.nextSibling);
        link.dataset.place = 'beside-add-question';
        return;
    }
    if (row) {
        row.appendChild(item);
        link.dataset.place = 'button-row';
        return;
    }
    ownrow();
};
