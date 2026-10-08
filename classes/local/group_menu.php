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
 * The groups menu of the analysis pages (1.1): Moodle's own groups, plus a line for each year above its classes, so a
 * teacher can see 10A and 10B together. A year is a grouping of the course with two or more of the classes, or classes
 * named alike (10A, 10B). Whom a teacher may see is decided by Moodle's group setting, as in Moodle's own menu.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class group_menu {
    /**
     * The years among some classes.
     *
     * @param string[] $groups the classes the teacher may see, [groupid => name], in menu order
     * @param array $groupings [groupingid => ['name' => string, 'groups' => int[]]]
     * @param bool $hasall the menu has "All participants" (a year with every class is then left out)
     * @return array [['key' => 'g<groupingid>' or 'n<first groupid>', 'name' => string, 'groups' => int[]]], the classes in
     *               menu order
     */
    public static function years(array $groups, array $groupings, bool $hasall): array {
        $years = [];
        $seen = [];
        $add = function (string $key, string $name, array $ids) use (&$years, &$seen, $groups, $hasall): void {
            $ids = array_values(array_intersect(array_keys($groups), $ids));
            $set = implode(',', $ids);
            if (count($ids) < 2 || isset($seen[$set]) || ($hasall && count($ids) === count($groups))) {
                return;
            }
            $seen[$set] = true;
            $years[] = ['key' => $key, 'name' => $name, 'groups' => $ids];
        };
        foreach ($groupings as $id => $g) {
            $add('g' . $id, get_string('group_yearnamed', 'local_quizbot', (string) $g['name']), $g['groups']);
        }
        // Classes named like 10A, 10 B, Year 10-C, Grade 9a: the same name up to a number, then one letter. The year is
        // called by that name: "All Grade 10" for 10A and 10B, "All Year 10" for Year 10-A and Year 10-B.
        $alike = [];
        foreach ($groups as $id => $name) {
            if (preg_match('/^(.*\d)\s*[-.\/]?\s*\p{L}$/u', trim($name), $m)) {
                $year = trim($m[1]);
                $alike[\core_text::strtolower($year)]['name'] = $alike[\core_text::strtolower($year)]['name'] ?? $year;
                $alike[\core_text::strtolower($year)]['ids'][] = $id;
            }
        }
        foreach ($alike as $a) {
            $name = preg_match('/^\d+$/', $a['name']) ? get_string('group_year', 'local_quizbot', $a['name'])
                : get_string('group_yearnamed', 'local_quizbot', $a['name']);
            $add('n' . min($a['ids']), $name, $a['ids']);
        }
        return $years;
    }

    /**
     * The menu for an analysis page, and whom it selects.
     *
     * @param \stdClass $course
     * @param \cm_info|null $cm the quiz, or null for the whole course
     * @param \moodle_url $url the page, without group or year
     * @return array ['html' => the menu, 'groups' => int[] the selected classes ([] = everybody), 'year' => the selected
     *               year's key or '', 'nogroup' => the teacher may see only their own classes and has none]
     */
    public static function for_page(\stdClass $course, ?\cm_info $cm, \moodle_url $url): array {
        global $DB, $OUTPUT, $SESSION, $USER;

        $none = ['html' => '', 'groups' => [], 'year' => '', 'nogroup' => false];
        $context = $cm ? \context_module::instance($cm->id) : \context_course::instance($course->id);
        $all = has_capability('moodle/site:accessallgroups', $context);
        if ($cm) {
            $mode = groups_get_activity_groupmode($cm);
            if ($mode == NOGROUPS) {
                return $none;
            }
            $allowed = groups_get_activity_allowed_groups($cm);
        } else {
            // A course without a group mode still has its classes: they are shown as visible groups.
            $mode = (int) $course->groupmode ?: VISIBLEGROUPS;
            $allowed = groups_get_all_groups(
                $course->id,
                $mode == SEPARATEGROUPS && !$all ? $USER->id : 0,
                $course->defaultgroupingid
            );
        }
        // The groups of remedial quizzes are not classes: a few students each, for one quiz.
        $remedial = $DB->get_fieldset_select('local_quizbot_remedial', 'groupid', 'courseid = ?', [$course->id]);
        $allowed = array_diff_key($allowed ?: [], array_flip($remedial));
        $hasall = $mode == VISIBLEGROUPS || $all;
        if (!$allowed) {
            return ['nogroup' => !$hasall] + $none;
        }
        if ($cm) {
            $active = (int) groups_get_activity_group($cm, true, $allowed);
        } else {
            $active = (int) groups_get_course_group((object) (['groupmode' => $mode] + (array) $course), true, $allowed);
        }
        if (!$active && !$hasall) {
            $active = (int) array_key_first($allowed);
        }

        $names = [];
        foreach ($allowed as $id => $g) {
            $names[$id] = format_string($g->name, true, ['context' => $context]);
        }
        $groupings = [];
        $rows = $DB->get_recordset_sql('SELECT gg.id, gg.groupingid, gg.groupid, g.name
              FROM {groupings_groups} gg
              JOIN {groupings} g ON g.id = gg.groupingid
             WHERE g.courseid = ?
          ORDER BY g.name, gg.groupingid', [$course->id]);
        foreach ($rows as $r) {
            $groupings[$r->groupingid]['name'] = format_string($r->name, true, ['context' => $context]);
            $groupings[$r->groupingid]['groups'][] = (int) $r->groupid;
        }
        $rows->close();
        $years = self::years($names, $groupings, $hasall);

        // The chosen year is remembered for the page, like Moodle remembers the chosen group; picking a group forgets it.
        $memory = 'c' . $context->id;
        $SESSION->local_quizbot_year = $SESSION->local_quizbot_year ?? [];
        $asked = optional_param('year', '', PARAM_ALPHANUM);
        if ($asked !== '') {
            $SESSION->local_quizbot_year[$memory] = $asked;
        } else if (optional_param('group', -1, PARAM_INT) != -1) {
            unset($SESSION->local_quizbot_year[$memory]);
        }
        $year = null;
        foreach ($years as $y) {
            if ($y['key'] === ($SESSION->local_quizbot_year[$memory] ?? '')) {
                $year = $y;
            }
        }

        $base = new \moodle_url($url);
        $base->remove_params('group', 'year');
        $link = fn (array $params): string => (new \moodle_url($base, $params))->out(false);
        $options = [];
        if ($hasall) {
            $options[$link(['group' => 0])] = get_string('allparticipants');
        }
        $listed = [];
        foreach ($names as $id => $name) {
            foreach ($years as $y) {
                if ($y['groups'][0] === (int) $id && !isset($listed[$y['key']])) {
                    $listed[$y['key']] = true;
                    $options[$link(['year' => $y['key']])] = $y['name'];
                }
            }
            $options[$link(['group' => $id])] = $name;
        }
        $selected = $year ? $link(['year' => $year['key']]) : $link(['group' => $active]);
        $select = new \url_select($options, $selected, null, 'local-quizbot-groupmenu');
        $select->set_label(get_string($mode == SEPARATEGROUPS ? 'groupsseparate' : ($cm || $course->groupmode
            ? 'groupsvisible' : 'group')));

        return [
            'html' => \html_writer::div($OUTPUT->render($select), 'groupselector'),
            'groups' => $year ? $year['groups'] : ($active ? [$active] : []),
            'year' => $year ? $year['key'] : '',
            'nogroup' => false,
        ];
    }
}
