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
 * Finds everything in a course that Quizbot can write questions from - wherever the teacher put it.
 *
 * Teachers add material in many ways: a file as its own activity, a video dropped into a text block, a YouTube
 * address as a link activity or typed into a page. So every activity the teacher can see is examined: the files in
 * its file areas, a link activity's address, and video links inside its text. The same YouTube video found twice is
 * listed once.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class source_finder {
    /** @var array file extension => kind of source */
    public const KINDS = [
        'pptx' => 'slides',
        'docx' => 'word', 'doc' => 'word',
        'xlsx' => 'excel', 'xls' => 'excel',
        'pdf' => 'pdf',
        'txt' => 'text', 'md' => 'text', 'csv' => 'text',
        'png' => 'picture', 'jpg' => 'picture', 'jpeg' => 'picture', 'webp' => 'picture', 'gif' => 'picture',
        'mp4' => 'video', 'm4v' => 'video', 'mov' => 'video', 'webm' => 'video',
        'mp3' => 'audio', 'm4a' => 'audio', 'wav' => 'audio', 'ogg' => 'audio',
    ];

    /** @var array activity type => the file areas that hold teaching material, each [component, filearea] */
    public const AREAS = [
        'resource' => [['mod_resource', 'content']],
        'folder' => [['mod_folder', 'content']],
        'label' => [['mod_label', 'intro']],
        'page' => [['mod_page', 'content'], ['mod_page', 'intro']],
        'book' => [['mod_book', 'chapter'], ['mod_book', 'intro']],
        'lesson' => [['mod_lesson', 'page_contents']],
        'url' => [],
    ];

    /** @var int a text block shorter than this (in characters of plain text) is not offered as a text source */
    public const MIN_TEXT = 200;

    /**
     * Everything usable in the course, grouped by course section.
     *
     * @param \stdClass $course
     * @param int $currentcmid the quiz the questions are for, so that it can be told apart from the course's other quizzes
     * @return array [ ['name' => section name, 'items' => [item, …]], … ]; an item has key, kind, name, detail
     *               and 'ref' (what to fetch when the questions are written)
     */
    public static function for_course(\stdClass $course, int $currentcmid = 0): array {
        $modinfo = get_fast_modinfo($course);
        $fs = get_file_storage();
        $sections = [];
        $videos = [];        // YouTube video id => [section index, item index], to list each video once.

        foreach ($modinfo->get_section_info_all() as $section) {
            $items = [];
            foreach ($modinfo->sections[$section->section] ?? [] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if ($cm->modname === 'quiz') {
                    // A quiz with questions: to write more questions like the ones it has.
                    if ($cm->uservisible && ($item = self::quiz_item($cm, $currentcmid))) {
                        $items[] = $item;
                    }
                    continue;
                }
                if (!$cm->uservisible || !array_key_exists($cm->modname, self::AREAS)) {
                    continue;
                }
                $context = \context_module::instance($cm->id);

                // 1. Files, in whichever file area of the activity they sit.
                foreach (self::AREAS[$cm->modname] as [$component, $area]) {
                    foreach ($fs->get_area_files($context->id, $component, $area, false, 'filepath, filename', false) as $file) {
                        $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
                        if (!isset(self::KINDS[$ext])) {
                            continue;
                        }
                        $items[] = self::file_item($file, self::KINDS[$ext], $cm);
                    }
                }

                // 2. Text of the activity: video links inside it, and the text itself.
                [$html, $chapters] = self::activity_html($cm);
                foreach (self::youtube_ids($html) as $videoid) {
                    $where = $cm->modname === 'url' ? 'detail_youtubeactivity' : 'detail_youtubeintext';
                    if (isset($videos[$videoid])) {
                        [$s, $i] = $videos[$videoid];
                        if ($s === count($sections)) {
                            $items[$i]['detail'] .= get_string('detail_alsoin', 'local_quizbot', $cm->get_formatted_name());
                        } else {
                            $sections[$s]['items'][$i]['detail'] .= get_string(
                                'detail_alsoin',
                                'local_quizbot',
                                $cm->get_formatted_name()
                            );
                        }
                        continue;
                    }
                    $videos[$videoid] = [count($sections), count($items)];
                    $items[] = [
                        'key' => 'y' . $videoid,
                        'kind' => 'youtube',
                        'name' => $cm->get_formatted_name(),
                        'detail' => get_string($where, 'local_quizbot', $cm->modname === 'url'
                            ? 'youtube.com/watch?v=' . $videoid : $cm->get_formatted_name()),
                        'ref' => ['type' => 'youtube', 'videoid' => $videoid],
                    ];
                }
                if ($cm->modname !== 'url' && $html !== '') {
                    $plain = trim(html_to_text($html, 0, false));
                    $length = \core_text::strlen($plain);
                    $onlylink = (bool) preg_match('~^\s*https?://\S+\s*$~', $plain);
                    if (!$onlylink && ($cm->modname !== 'label' || $length >= self::MIN_TEXT) && $length > 0) {
                        $words = count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY));
                        $items[] = [
                            'key' => 't' . $cm->id,
                            'kind' => 'page',
                            'name' => $cm->get_formatted_name(),
                            'detail' => $chapters
                                ? get_string('detail_book', 'local_quizbot', ['chapters' => $chapters, 'words' => $words])
                                : get_string(
                                    $cm->modname === 'label' ? 'detail_textblock' : 'detail_page',
                                    'local_quizbot',
                                    $words
                                ),
                            'ref' => ['type' => 'text', 'cmid' => $cm->id],
                        ];
                    }
                }
            }
            if ($items) {
                $sections[] = ['name' => get_section_name($course, $section), 'items' => $items];
            }
        }
        return $sections;
    }

    /**
     * The same list as one map, key => item.
     *
     * @param array $sections as returned by for_course()
     * @return array
     */
    public static function by_key(array $sections): array {
        $map = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $map[$item['key']] = $item;
            }
        }
        return $map;
    }

    /**
     * The course item a source key stands for, so students can be sent back to the material (1.1): an activity's
     * own text or a quiz ("t12", "q12"), or a file inside an activity ("f345"). 0 when it is not a course item
     * (an upload, a link, pasted text) or cannot be told from the key (a YouTube video found in a page).
     *
     * @param string $key
     * @return int course module id, or 0
     */
    public static function cmid_of(string $key): int {
        if (preg_match('/^[tq](\d+)$/', $key, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^f(\d+)$/', $key, $m) && ($file = get_file_storage()->get_file_by_id((int) $m[1]))) {
            $context = \context::instance_by_id($file->get_contextid(), IGNORE_MISSING);
            return $context && $context->contextlevel == CONTEXT_MODULE ? (int) $context->instanceid : 0;
        }
        return 0;
    }

    /**
     * One file as a list item.
     *
     * @param \stored_file $file
     * @param string $kind
     * @param \cm_info $cm the activity it belongs to
     * @return array
     */
    private static function file_item(\stored_file $file, string $kind, \cm_info $cm): array {
        $a = [
            'type' => get_string('type_' . $kind, 'local_quizbot'),
            'size' => display_size($file->get_filesize()),
            'activity' => $cm->get_formatted_name(),
        ];
        if ($cm->modname === 'resource') {
            $name = $cm->get_formatted_name();
            $detail = get_string('detail_file', 'local_quizbot', $a);
        } else {
            $name = pathinfo($file->get_filename(), PATHINFO_FILENAME);
            $detail = get_string($cm->modname === 'folder' ? 'detail_fileinfolder' : 'detail_fileinblock', 'local_quizbot', $a);
        }
        return [
            'key' => 'f' . $file->get_id(),
            'kind' => $kind,
            'name' => $name,
            'detail' => $detail,
            'ref' => ['type' => 'file', 'fileid' => (int) $file->get_id()],
        ];
    }

    /**
     * A quiz as a list item - only for someone who may edit that quiz, and only when it holds questions.
     *
     * @param \cm_info $cm
     * @param int $currentcmid the quiz the questions are for
     * @return array|null
     */
    private static function quiz_item(\cm_info $cm, int $currentcmid): ?array {
        if (!has_capability('mod/quiz:manage', \context_module::instance($cm->id))) {
            return null;
        }
        $count = count(self::quiz_question_ids($cm));
        if (!$count) {
            return null;
        }
        return [
            'key' => 'q' . $cm->id,
            'kind' => 'quiz',
            'name' => $cm->get_formatted_name(),
            'detail' => get_string((int) $cm->id === $currentcmid ? 'detail_quizthis' : 'detail_quiz', 'local_quizbot', $count),
            'ref' => ['type' => 'quiz', 'cmid' => (int) $cm->id],
        ];
    }

    /**
     * The questions a quiz holds, in its order. Random picks and descriptions are left out: they are not questions
     * with a text of their own.
     *
     * @param \cm_info $cm
     * @return int[] question ids
     */
    private static function quiz_question_ids(\cm_info $cm): array {
        $ids = [];
        $slots = \mod_quiz\question\bank\qbank_helper::get_question_structure(
            (int) $cm->instance,
            \context_module::instance($cm->id)
        );
        foreach ($slots as $slot) {
            if (
                !empty($slot->questionid) && is_numeric($slot->questionid)
                    && !in_array($slot->qtype ?? '', ['random', 'description', 'missingtype'], true)
            ) {
                $ids[] = (int) $slot->questionid;
            }
        }
        return $ids;
    }

    /**
     * The questions of a quiz as plain text, with their answers - what is sent when the teacher chose the quiz, so
     * that Quizbot can write new questions like them.
     *
     * @param \cm_info $cm
     * @return string
     */
    public static function quiz_text(\cm_info $cm): string {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');

        $plain = fn ($html) => trim(preg_replace('/\s+/u', ' ', html_to_text((string) $html, 0, false)) ?? '');
        $out = '';
        $number = 0;
        foreach (self::quiz_question_ids($cm) as $id) {
            try {
                $question = \question_bank::load_question_data($id);
            } catch (\Throwable $e) {
                continue;
            }
            $number++;
            $out .= "Question {$number} ({$question->qtype}): " . $plain($question->questiontext) . "\n";
            foreach ($question->options->answers ?? [] as $answer) {
                $out .= '  - ' . $plain($answer->answer) . ((float) $answer->fraction > 0 ? '  [correct]' : '') . "\n";
            }
            foreach ($question->options->subquestions ?? [] as $pair) {
                if (trim((string) ($pair->questiontext ?? '')) !== '') {
                    $out .= '  - ' . $plain($pair->questiontext) . ' -> ' . $plain($pair->answertext ?? '') . "\n";
                }
            }
            $out .= "\n";
        }
        return trim($out);
    }

    /**
     * The text of a page, book, lesson or text block as plain text - what is sent when the teacher chose it.
     *
     * @param \cm_info $cm
     * @return string
     */
    public static function text_for(\cm_info $cm): string {
        [$html] = self::activity_html($cm);
        return trim(html_to_text($html, 0, false));
    }

    /**
     * The text an activity holds, as HTML.
     *
     * @param \cm_info $cm
     * @return array [html, number of book chapters or 0]
     */
    private static function activity_html(\cm_info $cm): array {
        global $DB;

        switch ($cm->modname) {
            case 'label':
                return [(string) $DB->get_field('label', 'intro', ['id' => $cm->instance]), 0];
            case 'page':
                return [(string) $DB->get_field('page', 'content', ['id' => $cm->instance]), 0];
            case 'url':
                return [(string) $DB->get_field('url', 'externalurl', ['id' => $cm->instance]), 0];
            case 'book':
                $chapters = $DB->get_records(
                    'book_chapters',
                    ['bookid' => $cm->instance, 'hidden' => 0],
                    'pagenum',
                    'id, title, content'
                );
                $html = '';
                foreach ($chapters as $chapter) {
                    $html .= '<h3>' . s($chapter->title) . '</h3>' . $chapter->content;
                }
                return [$html, count($chapters)];
            case 'lesson':
                $pages = $DB->get_records('lesson_pages', ['lessonid' => $cm->instance], 'id', 'id, title, contents');
                $html = '';
                foreach ($pages as $page) {
                    $html .= '<h3>' . s($page->title) . '</h3>' . $page->contents;
                }
                return [$html, 0];
        }
        return ['', 0];
    }

    /**
     * The YouTube videos linked or embedded in a piece of HTML (or in a plain address).
     *
     * @param string $html
     * @return string[] distinct 11-character video ids, in the order found
     */
    public static function youtube_ids(string $html): array {
        $pattern = '~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^"\'\s<>]*?&(?:amp;)?)?v=|embed/|shorts/|live/)'
            . '|youtu\.be/)([A-Za-z0-9_-]{11})~';
        preg_match_all($pattern, $html, $m);
        return array_values(array_unique($m[1]));
    }
}
