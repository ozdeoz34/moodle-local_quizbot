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
 * The state of this site's Quizbot licence, as the Quizbot service sees it: whether it can be used, how many
 * questions are left (a school plan counts none), and whether the licence has a place for this teacher. Asked at
 * most every few minutes (Moodle's cache), not on every page.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class licence {
    /** @var string[] answers after which the wizard cannot be used at all */
    public const BLOCKING = ['licence_missing', 'licence_invalid', 'licence_inactive', 'licence_expired', 'site_mismatch',
        'limit_reached', 'teacher_limit'];

    /**
     * The licence's state.
     *
     * @param bool $fresh ask the service now, not the cache
     * @param bool $forteacher true: for the person who wants to write questions - no free teacher place is then a
     *                         state that stops the wizard; false: the licence itself (the administrator's page)
     * @return array ['ok' => bool, 'code' => '' or the problem, 'message' => the service's own words, 'summary' => plan,
     *               label, limit, used, remaining, unlimited, topup, teachers_limit, teachers_used, teacher_place,
     *               media_minutes_limit, media_minutes_used, period_end, site (when known)]
     */
    public static function status(bool $fresh = false, bool $forteacher = true): array {
        global $USER;

        $key = trim((string) get_config('local_quizbot', 'licencekey'));
        if ($key === '') {
            return ['ok' => false, 'code' => 'licence_missing', 'message' => get_string('errornokey', 'local_quizbot'),
                'summary' => []];
        }
        $cache = \cache::make('local_quizbot', 'licence');
        // A changed key or server is a different licence: it must not be answered from the old one's entry. And each
        // person has an entry of his own: whether there is a teacher place is said for the one who asks.
        $entry = sha1($key . '|' . get_config('local_quizbot', 'serverurl') . '|' . (int) ($USER->id ?? 0));
        $status = $fresh ? false : $cache->get($entry);
        if ($status === false) {
            try {
                $answer = (new api())->verify();
                $summary = $answer['licence'] ?? [];
                $status = ['ok' => true, 'code' => '', 'message' => '', 'summary' => $summary,
                    'teachermessage' => (string) ($answer['teacher_message'] ?? '')];
                if ((int) ($summary['remaining'] ?? 1) < 1) {
                    $status = ['ok' => false, 'code' => 'limit_reached', 'message' => '', 'summary' => $summary];
                }
            } catch (api_exception $e) {
                $status = ['ok' => false, 'code' => $e->errorcode, 'message' => $e->getMessage(), 'summary' => []];
            }
            if ($status['code'] !== 'unreachable' && $status['code'] !== 'busy') {
                $cache->set($entry, $status);
            }
        }
        if ($forteacher && $status['ok'] && ($status['summary']['teacher_place'] ?? true) === false) {
            return ['ok' => false, 'code' => 'teacher_limit', 'message' => (string) ($status['teachermessage'] ?? ''),
                'summary' => $status['summary']];
        }
        return $status;
    }

    /**
     * Forgets the cached state - after questions were added (fewer are left) or the settings changed.
     */
    public static function forget(): void {
        \cache::make('local_quizbot', 'licence')->purge();
    }

    /**
     * Does this state stop the wizard?
     *
     * @param array $status as returned by status()
     * @return bool
     */
    public static function blocks(array $status): bool {
        return !$status['ok'] && in_array($status['code'], self::BLOCKING, true);
    }

    /**
     * The day a licence ends, as this site writes dates; '' when it is not known.
     *
     * @param array $status as returned by status()
     * @return string
     */
    public static function until(array $status): string {
        $day = $status['summary']['period_end'] ?? '';
        return $day ? userdate(strtotime($day . ' 12:00'), get_string('strftimedate', 'langconfig')) : '';
    }

    /**
     * Why the wizard cannot be used, in a sentence or two. "All questions used" is said in three ways: a teacher
     * package (another one can be bought and is added at once), a school plan (its fair-use limit), any other licence.
     *
     * @param array $status as returned by status(), with a code that blocks
     * @return string
     */
    public static function blocked_text(array $status): string {
        $string = 'blocked_' . $status['code'] . '_text';
        if ($status['code'] === 'limit_reached' && !empty($status['summary']['topup'])) {
            $string = 'blocked_limit_reached_topup_text';
        } else if ($status['code'] === 'limit_reached' && !empty($status['summary']['unlimited'])) {
            $string = 'blocked_limit_reached_fair_text';
        }
        return get_string($string, 'local_quizbot', self::until($status));
    }

    /**
     * Can more be bought for this licence as it stands - another teacher package, added to it at once?
     *
     * @param array $status as returned by status()
     * @return bool
     */
    public static function can_buy_more(array $status): bool {
        return !empty($status['summary']['topup']) && in_array($status['code'], ['limit_reached', 'teacher_limit'], true);
    }

    /**
     * The licence's numbers in the words shown to people, or '' when they are not known.
     *
     * @param array $status as returned by status()
     * @return string
     */
    public static function left_text(array $status): string {
        $s = $status['summary'];
        if (!isset($s['remaining'], $s['limit'])) {
            return '';
        }
        if (!empty($s['unlimited'])) {
            return get_string('licenceleft_unlimited', 'local_quizbot', self::until($status) ?: '?');
        }
        return get_string('licenceleft', 'local_quizbot', [
            'left' => format_float((float) $s['remaining'], 0),
            'limit' => format_float((float) $s['limit'], 0),
            'until' => self::until($status) ?: '?',
        ]);
    }
}
