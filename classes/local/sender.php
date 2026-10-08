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
 * Packs what the teacher chose and hands it to the Quizbot service: the list of sources with the texts inside, then
 * the files one by one, then "begin".
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sender {
    /**
     * Sends a run.
     *
     * @param \stdClass $job the run, as returned by job::current()
     * @param \stdClass $course
     * @param \context_module $context the quiz's context (uploaded files are kept there)
     * @param array $bykey the course's sources by key, from source_finder::by_key()
     * @param array $extra more sources, as the service takes them: [id, kind, name, text] (a remedial quiz sends the
     *              questions its students got wrong as an existing quiz, or its topic)
     * @return array [the request's id at the service, source id => name as shown to the teacher]
     * @throws api_exception with a message for the teacher
     */
    public static function send(
        \stdClass $job,
        \stdClass $course,
        \context_module $context,
        array $bykey,
        array $extra = []
    ): array {
        $fs = get_file_storage();
        $modinfo = get_fast_modinfo($course);
        $sources = [];
        $files = [];
        $names = [];

        foreach ($job->sourcesdata['keys'] as $key) {
            if (!isset($bykey[$key])) {
                continue;       // Removed from the course since it was ticked.
            }
            $item = $bykey[$key];
            $source = ['id' => $key, 'kind' => $item['kind'], 'name' => $item['name']];
            if ($item['ref']['type'] === 'file') {
                $file = $fs->get_file_by_id($item['ref']['fileid']);
                if (!$file) {
                    continue;
                }
                $files[$key] = $file;
            } else if ($item['ref']['type'] === 'youtube') {
                $source['videoid'] = $item['ref']['videoid'];
            } else if ($item['ref']['type'] === 'quiz') {
                $source['text'] = source_finder::quiz_text($modinfo->get_cm($item['ref']['cmid']));
            } else {
                $source['text'] = source_finder::text_for($modinfo->get_cm($item['ref']['cmid']));
            }
            $sources[] = $source;
            $names[$key] = $item['name'];
        }

        foreach ($fs->get_area_files($context->id, 'local_quizbot', 'upload', $job->id, 'filename', false) as $file) {
            $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
            if (!isset(source_finder::KINDS[$ext])) {
                continue;
            }
            $key = 'u' . $file->get_id();
            $sources[] = ['id' => $key, 'kind' => source_finder::KINDS[$ext], 'name' => $file->get_filename()];
            $files[$key] = $file;
            $names[$key] = $file->get_filename();
        }

        // The link was read when the teacher gave it: a page travels as its text, a document as a file, a YouTube
        // video as its id (Quizbot reads what is said in it).
        $link = $job->sourcesdata['link'];
        if ($link['type'] === 'youtube') {
            $sources[] = ['id' => 'link', 'kind' => 'youtube', 'name' => $link['name'], 'videoid' => $link['videoid']];
            $names['link'] = $link['name'];
        } else if ($link['type'] === 'page' && trim($link['text']) !== '') {
            $sources[] = ['id' => 'link', 'kind' => 'link', 'name' => $link['name'], 'text' => $link['text']];
            $names['link'] = $link['name'];
        } else if (
            $link['type'] === 'file' && isset(source_finder::KINDS[$link['ext']]) && ($file = link_reader::file(
                $context,
                $job->id
            ))
        ) {
            $sources[] = ['id' => 'link', 'kind' => source_finder::KINDS[$link['ext']], 'name' => $link['name']];
            $files['link'] = $file;
            $names['link'] = $link['name'];
        }

        if (trim($job->sourcesdata['text']) !== '') {
            $names['pasted'] = get_string('sourcepasted', 'local_quizbot');
            $sources[] = ['id' => 'pasted', 'kind' => 'pasted', 'name' => $names['pasted'], 'text' => $job->sourcesdata['text']];
        }
        foreach ($extra as $source) {
            $sources[] = $source;
            $names[$source['id']] = $source['name'];
        }
        if (!$sources) {
            throw new api_exception(get_string('errornosources', 'local_quizbot'), 'no_sources');
        }

        $options = array_intersect_key($job->optionsdata, array_flip(['counts', 'language', 'level', 'difficulty', 'feedback',
            'spread']));
        // The teacher's Bloom's levels go as an exact plan: how many questions of each kind at each level.
        if (!empty($job->optionsdata['bloomon'])) {
            $options['bloom'] = bloom::plan($job->optionsdata['counts'], bloom::clean($job->optionsdata['skills'] ?? []))['plan'];
        }

        // A large video takes a while to travel: this page must not be cut off half-way.
        \core_php_time_limit::raise(600);
        $api = new api();
        $created = $api->create($options, $sources);
        foreach ($created['upload'] ?? [] as $id) {
            if (isset($files[$id])) {
                $api->add_file($created['job'], $id, $files[$id]);
            }
        }
        $api->start($created['job']);
        return [(string) $created['job'], $names];
    }
}
