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

namespace local_quizbot\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The "Upload a file" tab: files from the teacher's computer, used as sources without being added to the course.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_form extends \moodleform {
    /** @var int the most files one run may take from the teacher's computer */
    public const MAX_FILES = \local_quizbot\local\job::MAX_SOURCES;

    /**
     * The file picker's limits. The same array is needed to prepare and to save the draft area.
     *
     * @param int $maxbytes the course's upload limit
     * @return array
     */
    public static function options(int $maxbytes): array {
        $types = array_map(fn ($ext) => '.' . $ext, array_keys(\local_quizbot\local\source_finder::KINDS));
        return ['subdirs' => 0, 'maxfiles' => self::MAX_FILES, 'maxbytes' => $maxbytes, 'accepted_types' => $types];
    }

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'step', 'sources');
        $mform->setType('step', PARAM_ALPHA);
        $mform->addElement('hidden', 'tab', 'upload');
        $mform->setType('tab', PARAM_ALPHA);

        $mform->addElement(
            'filemanager',
            'files',
            get_string('uploadfiles', 'local_quizbot'),
            null,
            self::options($this->_customdata['maxbytes'])
        );

        $this->add_action_buttons(false, get_string('uploadsave', 'local_quizbot'));
    }
}
