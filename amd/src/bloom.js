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
 * Step 2 of the wizard, the Bloom's section: shows the rows only when the box is ticked, offers quick choices, says at
 * once when the numbers cannot be met, and shows how the questions will be written (kind x level). The plan is worked
 * out exactly as the server side does it (classes/local/bloom.php): the most constrained levels first, each placed on
 * the most natural free kind, earlier choices moved along when a later level needs their kind. Without script the
 * page still works: the server checks the numbers when "Generate" is pressed.
 *
 * @module     local_quizbot/bloom
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The plan: [type][level] counts, free questions per kind, and how many asked-for questions could not be placed.
 *
 * @param {Object} cfg the page's settings (fits, levels, types)
 * @param {Object} counts kind => number of questions
 * @param {Object} skills level => number wanted
 * @returns {Object} {flow, left, unmet}
 */
const plan = (cfg, counts, skills) => {
    const left = {}, flow = {};
    cfg.types.forEach((t) => {
        left[t] = Math.max(0, counts[t] || 0);
        flow[t] = {};
        cfg.levels.forEach((l) => {
            flow[t][l] = 0;
        });
    });
    const augment = (start) => {
        const parentType = {}, parentLevel = {}, seenLevel = {[start]: true}, seenType = {};
        const queue = [start];
        while (queue.length) {
            const level = queue.shift();
            for (const type of cfg.fits[level]) {
                if (seenType[type]) {
                    continue;
                }
                seenType[type] = true;
                parentType[type] = level;
                if (left[type] > 0) {
                    left[type]--;
                    let t = type, l = parentType[type];
                    for (;;) {
                        flow[t][l]++;
                        if (l === start) {
                            return true;
                        }
                        const prev = parentLevel[l];
                        flow[prev][l]--;
                        t = prev;
                        l = parentType[t];
                    }
                }
                cfg.levels.forEach((other) => {
                    if (!seenLevel[other] && flow[type][other] > 0) {
                        seenLevel[other] = true;
                        parentLevel[other] = type;
                        queue.push(other);
                    }
                });
            }
        }
        return false;
    };
    let unmet = 0;
    Object.keys(cfg.fits).forEach((level) => {
        for (let k = 0; k < (skills[level] || 0); k++) {
            if (!augment(level)) {
                unmet++;
            }
        }
    });
    return {flow, left, unmet};
};

/**
 * The group of levels asking for more questions than its kinds have (the largest shortfall), or null.
 *
 * @param {Object} cfg the page's settings
 * @param {Object} counts kind => number
 * @param {Object} skills level => number
 * @returns {Object|null}
 */
const misfit = (cfg, counts, skills) => {
    let worst = null;
    // Every group of levels, in the order the server side takes them (as binary numbers 1, 10, 11, 100, ...).
    for (let mask = 1; mask < Math.pow(2, cfg.levels.length); mask++) {
        const levels = cfg.levels.filter((l, i) => Math.floor(mask / Math.pow(2, i)) % 2 === 1);
        const wanted = levels.reduce((a, l) => a + (skills[l] || 0), 0);
        if (!wanted) {
            continue;
        }
        const kinds = cfg.types.filter((t) => levels.some((l) => cfg.fits[l].indexOf(t) !== -1));
        const have = kinds.reduce((a, t) => a + (counts[t] || 0), 0);
        const gap = wanted - have;
        if (gap > 0 && (!worst || gap > worst.gap || (gap === worst.gap && levels.length < worst.levels.length))) {
            worst = {levels, kinds, wanted, have, gap};
        }
    }
    return worst;
};

/**
 * Fills {name} places in a sentence.
 *
 * @param {string} text
 * @param {Object} values
 * @returns {string}
 */
const fill = (text, values) => text.replace(/\{(\w+)\}/g, (all, key) => (key in values ? String(values[key]) : all));

/**
 * Start listening.
 */
export const init = () => {
    const box = document.getElementById('local-quizbot-bloom');
    const form = document.getElementById('local-quizbot-options');
    if (!box || !form) {
        return;
    }
    const cfg = JSON.parse(box.getAttribute('data-bloom') || '{}');
    const on = document.getElementById('local-quizbot-bloomon');
    const rows = document.getElementById('local-quizbot-bloomrows');
    const sum = document.getElementById('local-quizbot-bloomsum');
    const msg = document.getElementById('local-quizbot-bloommsg');
    const details = document.getElementById('local-quizbot-bloomplan');
    const table = document.getElementById('local-quizbot-bloomtable');
    const presets = document.getElementById('local-quizbot-bloompresets');
    const generate = form.querySelector('button[name="action"][value="next"]');
    if (!cfg.levels || !on || !rows) {
        return;
    }
    presets.hidden = false;

    const read = (prefix, keys) => {
        const out = {};
        keys.forEach((k) => {
            const input = form.querySelector('input[name="' + prefix + k + '"]');
            const n = input ? parseInt(input.value, 10) : 0;
            out[k] = n > 0 ? n : 0;
        });
        return out;
    };

    const cell = (tag, text) => {
        const el = document.createElement(tag);
        el.textContent = text;
        return el;
    };

    const update = () => {
        rows.hidden = !on.checked;
        const counts = read('n_', cfg.types);
        const skills = read('b_', cfg.levels);
        const total = cfg.types.reduce((a, t) => a + counts[t], 0);
        const chosen = cfg.levels.reduce((a, l) => a + skills[l], 0);
        let problem = '';
        if (on.checked && chosen > total) {
            problem = fill(cfg.str.over, {skills: chosen, total: total});
        } else if (on.checked) {
            const worst = misfit(cfg, counts, skills);
            if (worst) {
                problem = fill(cfg.str.misfit, {
                    levels: worst.levels.map((l) => cfg.names[l]).join(', '),
                    kinds: worst.kinds.map((t) => cfg.typenames[t]).join(', '),
                    wanted: worst.wanted,
                    have: worst.have,
                });
            }
        }
        sum.textContent = fill(cfg.str.sum, {skills: chosen, total: total})
            + (total > chosen && !problem ? ' ' + fill(cfg.str.left, {left: total - chosen}) : '');
        msg.textContent = problem;
        msg.hidden = problem === '';
        if (generate) {
            generate.disabled = on.checked && problem !== '';
        }

        // the table of how the questions will be written
        while (table.firstChild) {
            table.removeChild(table.firstChild);
        }
        details.hidden = !on.checked || problem !== '' || chosen === 0;
        if (details.hidden) {
            return;
        }
        const result = plan(cfg, counts, skills);
        const used = cfg.levels.filter((l) => skills[l] > 0);
        const free = cfg.types.some((t) => result.left[t] > 0);
        const head = document.createElement('tr');
        head.appendChild(cell('th', cfg.str.kind));
        used.forEach((l) => head.appendChild(cell('th', cfg.names[l])));
        if (free) {
            head.appendChild(cell('th', cfg.str.free));
        }
        const thead = document.createElement('thead');
        thead.appendChild(head);
        table.appendChild(thead);
        const tbody = document.createElement('tbody');
        cfg.types.filter((t) => counts[t] > 0).forEach((t) => {
            const tr = document.createElement('tr');
            const name = cell('th', cfg.typenames[t]);
            name.setAttribute('scope', 'row');
            name.className = 'text-start text-left fw-normal font-weight-normal';
            tr.appendChild(name);
            used.forEach((l) => tr.appendChild(cell('td', result.flow[t][l] || '–')));
            if (free) {
                tr.appendChild(cell('td', result.left[t] || '–'));
            }
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
    };

    // A quick choice: the questions asked for, shared out by weight, one at a time, each only where it still fits.
    const weights = {
        even: {remember: 1, understand: 1, apply: 1, analyse: 1, evaluate: 1, create: 1},
        basics: {remember: 4, understand: 4, apply: 2},
        higher: {apply: 3, analyse: 3, evaluate: 2, create: 2},
        clear: {},
    };
    presets.addEventListener('click', (e) => {
        const button = e.target.closest('button[data-preset]');
        if (!button) {
            return;
        }
        const w = weights[button.getAttribute('data-preset')] || {};
        const counts = read('n_', cfg.types);
        const all = Object.keys(w).reduce((a, l) => a + w[l], 0);
        const total = all > 0 ? Math.min(cfg.max, cfg.types.reduce((a, t) => a + counts[t], 0)) : 0;
        const skills = {};
        cfg.levels.forEach((l) => {
            skills[l] = 0;
        });
        for (let u = 0; u < total; u++) {
            let best = null, need = -Infinity;
            cfg.levels.forEach((l) => {
                if (!w[l]) {
                    return;
                }
                const n = w[l] / all * total - skills[l];
                if (n <= need) {
                    return;
                }
                const trial = Object.assign({}, skills, {[l]: skills[l] + 1});
                if (plan(cfg, counts, trial).unmet === 0) {
                    best = l;
                    need = n;
                }
            });
            if (best === null) {
                break;
            }
            skills[best]++;
        }
        cfg.levels.forEach((l) => {
            const input = form.querySelector('input[name="b_' + l + '"]');
            if (input) {
                input.value = skills[l];
            }
        });
        update();
    });

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
};
