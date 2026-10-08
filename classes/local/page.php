<?php
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

namespace local_quizbot\local;

/**
 * The frame of the plugin's own pages: a "desk" in the Quizbot style (styles.css) holding the page's title and
 * everything below it, inside the theme's page. Only the plugin's pages carry it; the rest of Moodle is unchanged.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page {
    /**
     * Opens the desk with the Quizbot line and the page's title. Close it with html_writer::end_div() before the
     * footer.
     *
     * @param string $title
     * @return string HTML
     */
    public static function desk_start(string $title): string {
        global $OUTPUT;

        return \html_writer::start_div('local-quizbot-desk')
            . \html_writer::div(get_string('brand', 'local_quizbot'), 'local-quizbot-brand')
            . $OUTPUT->heading($title, 2, 'local-quizbot-title');
    }
}
