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
 * Quizbot for Moodle: the administrator's settings (licence key, server address).
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_quizbot', get_string('pluginname', 'local_quizbot'));
    $ADMIN->add('localplugins', $settings);
    $ADMIN->add('localplugins', new admin_externalpage(
        'local_quizbot_licence',
        get_string('licencestatus', 'local_quizbot'),
        new moodle_url('/local/quizbot/licence.php')
    ));
    // How the site uses Quizbot (1.1): with the site's other reports.
    $ADMIN->add('reports', new admin_externalpage(
        'local_quizbot_usage',
        get_string('usage', 'local_quizbot'),
        new moodle_url('/local/quizbot/usage.php')
    ));

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configpasswordunmask(
            'local_quizbot/licencekey',
            get_string('licencekey', 'local_quizbot'),
            get_string('licencekey_desc', 'local_quizbot'),
            ''
        ));
        $settings->add(new admin_setting_configtext(
            'local_quizbot/serverurl',
            get_string('serverurl', 'local_quizbot'),
            get_string('serverurl_desc', 'local_quizbot'),
            'https://quizbot.ai',
            PARAM_URL
        ));
        $settings->add(new admin_setting_configtext(
            'local_quizbot/supportemail',
            get_string('supportemail', 'local_quizbot'),
            get_string('supportemail_desc', 'local_quizbot'),
            'info@quizbot.ai',
            PARAM_EMAIL
        ));
    }
}
