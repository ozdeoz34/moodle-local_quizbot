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
 * Who may use Quizbot on a quiz: one rule, used by the button, the navigation entry and the wizard itself.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /**
     * May the current user write questions with Quizbot for the quiz in this context?
     *
     * @param \context $context the quiz's module context
     * @return bool
     */
    public static function can_generate(\context $context): bool {
        return $context->contextlevel == CONTEXT_MODULE
            && has_capability('mod/quiz:manage', $context)
            && has_capability('local/quizbot:generate', $context);
    }

    /**
     * May the current user see the Quizbot Analysis of the quiz in this context (1.1)?
     *
     * @param \context $context the quiz's module context
     * @return bool
     */
    public static function can_analyse(\context $context): bool {
        return $context->contextlevel == CONTEXT_MODULE
            && has_capability('mod/quiz:viewreports', $context)
            && has_capability('local/quizbot:viewanalysis', $context);
    }
}
