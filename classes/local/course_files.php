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
 * Files a teacher brought into a run from outside the course - uploaded, or behind a link - put into the course as a
 * File activity when the questions are added, if the teacher ticks them. They go into the quiz's own section, HIDDEN
 * from students: the teacher decides in the course whether students see them.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_files {
    /**
     * The files of a run that could go into the course.
     *
     * @param \context $context the quiz's context
     * @param int $jobid
     * @return \stored_file[] by file id
     */
    public static function candidates(\context $context, int $jobid): array {
        $fs = get_file_storage();
        $files = [];
        foreach (['upload', 'link'] as $area) {
            foreach ($fs->get_area_files($context->id, 'local_quizbot', $area, $jobid, 'filename', false) as $file) {
                $files[(int) $file->get_id()] = $file;
            }
        }
        return $files;
    }

    /**
     * May this user add a File activity to the course?
     *
     * @param \stdClass $course
     * @return bool
     */
    public static function can_add(\stdClass $course): bool {
        $context = \context_course::instance($course->id);
        return has_capability('moodle/course:manageactivities', $context) && has_capability('mod/resource:addinstance', $context);
    }

    /**
     * Puts one file into the course as a hidden File activity in the quiz's section.
     *
     * @param \stdClass $course
     * @param \cm_info $cm the quiz
     * @param \stored_file $file
     * @return int the new activity's course module id
     */
    public static function add(\stdClass $course, \cm_info $cm, \stored_file $file): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/resourcelib.php');

        // Moodle's own way in: a draft area holding the file, as if it had been dropped into the activity's form.
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $file->get_filename(),
        ], $file);

        $info = (object) [
            'modulename' => 'resource',
            'course' => $course->id,
            'section' => $cm->sectionnum,
            'visible' => 0,
            'name' => shorten_text(pathinfo($file->get_filename(), PATHINFO_FILENAME), 250),
            'introeditor' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()],
            'files' => $draftid,
            'display' => RESOURCELIB_DISPLAY_AUTO,
            'printintro' => 0,
            'showsize' => 1,
            'showtype' => 1,
            'showdate' => 0,
            'filterfiles' => 0,
        ];
        return (int) create_module($info)->coursemodule;
    }
}
