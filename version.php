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

/**
 * Quizbot for Moodle: version information.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_quizbot';
$plugin->version = 2026100906;
$plugin->requires = 2022112800;        // Moodle 4.1.
// Tested on 4.1, 4.2, 4.3, 4.4, 4.5, 5.0, 5.1 and 5.2 (stage); PHPUnit also on 4.1 with PHP 7.4 and on PostgreSQL.
$plugin->supported = [401, 502];
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '1.2.0';
