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
 * Reads the link a teacher gives as a source: a web page, a YouTube video, or a document behind a link.
 *
 * The page is fetched by this Moodle site, with Moodle's own curl class, so the site's rules for outgoing requests
 * apply (blocked hosts, allowed ports, proxy) at the first address and at every redirect. Quizbot only receives the
 * text that was read; it never opens an address a teacher typed.
 *
 * @package    local_quizbot
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_reader {
    /** @var int a document behind a link may be this large, in bytes */
    public const MAX_BYTES = 26214400;

    /** @var int a web page may be this large, in bytes */
    public const MAX_PAGE_BYTES = 5242880;

    /** @var int no more of a page's text than this is kept, in characters */
    public const MAX_CHARS = 100000;

    /** @var int a page with less text than this has nothing to write questions from, in characters */
    public const MIN_CHARS = 300;

    /** @var array content type => file extension, for the documents Quizbot reads from a link */
    public const FILETYPES = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/msword' => 'doc',
    ];

    /** @var array file extension => how such a file begins */
    public const SIGNATURES = ['pdf' => '%PDF', 'docx' => "PK\x03\x04", 'pptx' => "PK\x03\x04", 'xlsx' => "PK\x03\x04",
        'doc' => "\xD0\xCF\x11\xE0"];

    /** @var string[] elements that start a new line in the text */
    public const BLOCKS = ['p', 'div', 'section', 'article', 'main', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'h1', 'h2', 'h3', 'h4',
        'h5', 'h6',
        'tr', 'table', 'blockquote', 'pre', 'figure', 'figcaption', 'caption', 'address', 'hr'];

    /** @var array what "no link" looks like in a run's sources */
    public const NONE = ['url' => '', 'type' => '', 'name' => '', 'videoid' => '', 'text' => '', 'words' => 0, 'ext' => '',
        'size' => 0];

    /**
     * Reads a link.
     *
     * @param string $typed what the teacher typed
     * @param \context $context the quiz's context: a document behind the link is kept there until the run ends
     * @param int $jobid the run
     * @return array like NONE, with type 'youtube', 'page' or 'file'
     * @throws \moodle_exception with a message for the teacher
     */
    public static function read(string $typed, \context $context, int $jobid): array {
        global $CFG;

        self::forget($context, $jobid);
        $url = self::tidy($typed);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === strtolower((string) parse_url($CFG->wwwroot, PHP_URL_HOST))) {
            throw new \moodle_exception('linkerror_ownsite', 'local_quizbot');
        }

        if (preg_match('~(^|\.)(youtube\.com|youtube-nocookie\.com|youtu\.be)$~', $host)) {
            $ids = source_finder::youtube_ids($url);
            if (!$ids) {
                throw new \moodle_exception('linkerror_youtube', 'local_quizbot');
            }
            return ['url' => $typed, 'type' => 'youtube', 'name' => self::youtube_title($ids[0]),
                'videoid' => $ids[0]] + self::NONE;
        }

        // Every address is checked before it is opened - the one the teacher gave and each one it sends us on to - so
        // redirects are followed here, one at a time, and not by the request itself.
        $path = make_request_directory() . '/link';
        $max = self::MAX_BYTES;
        $current = $url;
        for ($hop = 0;; $hop++) {
            self::check_address($current);
            $curl = new \curl();
            $result = $curl->download_one($current, null, [
                'filepath' => $path,
                'CURLOPT_FOLLOWLOCATION' => false,
                'CURLOPT_CONNECTTIMEOUT' => 8,
                'CURLOPT_TIMEOUT' => 25,
                'CURLOPT_ENCODING' => '',
                'CURLOPT_MAXFILESIZE' => $max,
                'CURLOPT_NOPROGRESS' => false,
                // The size is not always announced: stop as soon as more than the most allowed has arrived.
                'CURLOPT_PROGRESSFUNCTION' => fn($handle, $expected, $received) => $received > $max ? 1 : 0,
                'CURLOPT_USERAGENT' => 'Mozilla/5.0 (compatible; QuizbotForMoodle; +https://quizbot.ai)',
            ]);
            if ($result !== true) {
                if ($result === $curl->get_security()->get_blocked_url_string()) {
                    throw self::refused((string) parse_url($current, PHP_URL_HOST));
                }
                if (in_array($curl->get_errno(), [CURLE_FILESIZE_EXCEEDED, CURLE_ABORTED_BY_CALLBACK], true)) {
                    throw new \moodle_exception('linkerror_toolarge', 'local_quizbot', '', (int) ($max / 1048576));
                }
                throw new \moodle_exception('linkerror_unreachable', 'local_quizbot');
            }
            $info = $curl->get_info();
            $code = (int) ($info['http_code'] ?? 0);
            $next = (string) ($info['redirect_url'] ?? '');
            if ($code >= 300 && $code < 400 && $next !== '') {
                if ($hop >= 5 || !preg_match('~^https?://~i', $next)) {
                    throw new \moodle_exception('linkerror_unreachable', 'local_quizbot');
                }
                $current = $next;
                continue;
            }
            break;
        }
        $info['url'] = $current;
        if (in_array($code, [401, 403, 407, 429], true)) {
            throw new \moodle_exception('linkerror_refused', 'local_quizbot');
        }
        if ($code < 200 || $code >= 300) {
            throw new \moodle_exception(
                $code === 404 || $code === 410 ? 'linkerror_notfound' : 'linkerror_unreachable',
                'local_quizbot'
            );
        }

        $size = (int) filesize($path);
        $contenttype = (string) ($info['content_type'] ?? '');
        $type = strtolower(trim(explode(';', $contenttype)[0]));
        $final = (string) ($info['url'] ?? $url);
        $urlext = strtolower(pathinfo((string) parse_url($final, PHP_URL_PATH), PATHINFO_EXTENSION));
        $start = (string) file_get_contents($path, false, null, 0, 1024);

        // A document: said so by the server, or by the address when the server only says "a file".
        $ext = self::FILETYPES[$type] ?? '';
        if (
            $ext === '' && in_array($type, ['', 'application/octet-stream', 'binary/octet-stream', 'application/zip'], true)
                && isset(self::SIGNATURES[$urlext])
        ) {
            $ext = $urlext;
        }
        if ($ext !== '') {
            if (strpos($start, self::SIGNATURES[$ext]) !== 0) {
                throw new \moodle_exception('linkerror_kind', 'local_quizbot');
            }
            $name = clean_param(rawurldecode(basename((string) parse_url($final, PHP_URL_PATH))), PARAM_FILE);
            if ($name === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $ext) {
                $name = ($name === '' ? 'document' : $name) . '.' . $ext;
            }
            get_file_storage()->create_file_from_pathname(['contextid' => $context->id, 'component' => 'local_quizbot',
                'filearea' => 'link',
                'itemid' => $jobid, 'filepath' => '/', 'filename' => $name], $path);
            return ['url' => $typed, 'type' => 'file', 'name' => $name, 'ext' => $ext, 'size' => $size] + self::NONE;
        }

        $ishtml = in_array($type, ['text/html', 'application/xhtml+xml'], true) || ($type === '' && stripos(
            $start,
            '<html'
        ) !== false);
        if (!$ishtml && !in_array($type, ['text/plain', 'text/markdown', 'text/csv'], true)) {
            throw new \moodle_exception('linkerror_kind', 'local_quizbot');
        }
        if ($size > self::MAX_PAGE_BYTES) {
            throw new \moodle_exception('linkerror_toolarge', 'local_quizbot', '', (int) (self::MAX_PAGE_BYTES / 1048576));
        }
        $raw = self::to_utf8((string) file_get_contents($path), $contenttype);
        [$title, $text] = $ishtml ? self::page_text($raw) : ['', self::tidy_text($raw)];
        if (\core_text::strlen($text) < self::MIN_CHARS) {
            throw new \moodle_exception('linkerror_notext', 'local_quizbot');
        }
        $text = \core_text::substr($text, 0, self::MAX_CHARS);
        if ($title === '') {
            // A plain text file has no title: its file name says more than the site's name.
            $title = self::one_line(rawurldecode(basename((string) parse_url($final, PHP_URL_PATH))));
        }
        return [
            'url' => $typed,
            'type' => 'page',
            'name' => $title !== '' ? $title : $host,
            'text' => $text,
            'words' => count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY)),
        ] + self::NONE;
    }

    /**
     * May this address be opened? Two rules, both must allow it:
     * Moodle's own (the site administrator's blocked hosts and allowed ports), and one of the plugin's that no
     * setting can switch off - never an address inside the server's own network (loopback, private ranges, link-local,
     * which is also where cloud servers keep their metadata). What is read from a link is sent to the Quizbot service;
     * a teacher's link must not be a way to send it something from inside the school's network.
     *
     * @param string $url
     * @throws \moodle_exception
     */
    private static function check_address(string $url): void {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '') {
            throw new \moodle_exception('linkerror_invalid', 'local_quizbot');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            $addresses = gethostbynamel($host) ?: [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                $addresses[] = $record['ipv6'];
            }
            if (!$addresses) {
                throw new \moodle_exception('linkerror_unreachable', 'local_quizbot');
            }
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \moodle_exception('linkerror_blocked', 'local_quizbot');
            }
        }
        if ((new \core\files\curl_security_helper())->url_is_blocked($url)) {
            throw self::refused($host);
        }
    }

    /**
     * What to say when Moodle's rules turn an address away. Moodle also turns away a name that leads nowhere; to the
     * teacher that is a wrong address, not a rule.
     *
     * @param string $host
     * @return \moodle_exception
     */
    private static function refused(string $host): \moodle_exception {
        $known = filter_var($host, FILTER_VALIDATE_IP) || checkdnsrr($host . '.', 'A') || checkdnsrr($host . '.', 'AAAA');
        return new \moodle_exception($known ? 'linkerror_blocked' : 'linkerror_unreachable', 'local_quizbot');
    }

    /**
     * Removes the document kept for a run's link, if there is one.
     *
     * @param \context $context
     * @param int $jobid
     */
    public static function forget(\context $context, int $jobid): void {
        get_file_storage()->delete_area_files($context->id, 'local_quizbot', 'link', $jobid);
    }

    /**
     * The document kept for a run's link.
     *
     * @param \context $context
     * @param int $jobid
     * @return \stored_file|null
     */
    public static function file(\context $context, int $jobid): ?\stored_file {
        $files = get_file_storage()->get_area_files($context->id, 'local_quizbot', 'link', $jobid, 'id', false);
        return $files ? reset($files) : null;
    }

    /**
     * What was typed, as an address that can be fetched: http or https only.
     *
     * @param string $typed
     * @return string
     * @throws \moodle_exception
     */
    private static function tidy(string $typed): string {
        $url = trim($typed);
        if ($url !== '' && !preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = 'https://' . $url;       // Typed as "www.example.org/page".
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (
            strlen($url) > 2000 || !preg_match('~^https?://~i', $url) || strpos($host, '.') === false
                || clean_param($url, PARAM_URL) === ''
        ) {
            throw new \moodle_exception('linkerror_invalid', 'local_quizbot');
        }
        return $url;
    }

    /**
     * A YouTube video's title, asked from YouTube's public "oEmbed" address. Only the title is asked for here; the
     * words spoken in the video are read by Quizbot when the questions are written.
     *
     * @param string $videoid
     * @return string the title, or a general name when YouTube does not give one
     * @throws \moodle_exception when YouTube says there is no such video
     */
    private static function youtube_title(string $videoid): string {
        $curl = new \curl();
        $json = $curl->get(
            'https://www.youtube.com/oembed',
            ['format' => 'json', 'url' => 'https://www.youtube.com/watch?v=' . $videoid],
            ['CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_TIMEOUT' => 8]
        );
        $code = (int) ($curl->get_info()['http_code'] ?? 0);
        if (!$curl->get_errno() && in_array($code, [400, 404], true)) {
            throw new \moodle_exception('linkerror_youtubegone', 'local_quizbot');
        }
        $data = $code === 200 ? json_decode((string) $json, true) : null;
        $title = is_array($data) ? self::one_line((string) ($data['title'] ?? '')) : '';
        return $title !== '' ? $title : get_string('linkyoutube', 'local_quizbot');
    }

    /**
     * A page's bytes as UTF-8 text, whatever it was written in.
     *
     * @param string $raw
     * @param string $contenttype the Content-Type header
     * @return string
     */
    private static function to_utf8(string $raw, string $contenttype): string {
        $charset = '';
        if (
            preg_match('/charset\s*=\s*"?([A-Za-z0-9._-]+)/i', $contenttype, $m)
                || preg_match('/<meta[^>]+charset\s*=\s*["\']?([A-Za-z0-9._-]+)/i', substr($raw, 0, 4096), $m)
        ) {
            $charset = strtolower($m[1]);
        }
        if ($charset === '' || $charset === 'utf-8' || $charset === 'utf8') {
            $charset = mb_check_encoding($raw, 'UTF-8') ? '' : 'windows-1252';
        }
        if ($charset !== '') {
            $converted = \core_text::convert($raw, $charset, 'utf-8');
            $raw = $converted !== '' ? $converted : $raw;
        }
        return fix_utf8($raw);
    }

    /**
     * The title and the readable text of a web page: menus, scripts, forms' controls and footers are left out, and
     * the main part of the page is used when the page marks one.
     *
     * @param string $html UTF-8
     * @return array [title, text]
     */
    private static function page_text(string $html): array {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Every non-ASCII character as a number, so that the parser cannot mistake the page's encoding.
        $dom->loadHTML(
            mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);

        $title = '';
        foreach (['//meta[@property="og:title"]/@content', '//title'] as $query) {
            $found = $xpath->query($query);
            if ($found && $found->length && self::one_line($found->item(0)->textContent) !== '') {
                $title = self::one_line($found->item(0)->textContent);
                break;
            }
        }

        $unwanted = '//script | //style | //noscript | //template | //svg | //iframe | //nav | //header | //footer | //aside'
            . ' | //button | //select | //textarea | //*[@hidden] | //*[@aria-hidden="true"]'
            . ' | //*[@role="navigation" or @role="banner" or @role="contentinfo" or @role="search" or @role="dialog"]'
            . ' | //sup[contains(@class, "reference")] | //*[contains(@class, "mw-editsection")]';
        foreach (iterator_to_array($xpath->query($unwanted)) as $node) {
            if ($node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
        if (!$body) {
            return [$title, ''];
        }
        $all = self::tidy_text(self::node_text($body));
        foreach (['//main', '//*[@role="main"]', '//article'] as $query) {
            $found = $xpath->query($query);
            if ($found && $found->length === 1) {
                $main = self::tidy_text(self::node_text($found->item(0)));
                // Trust the marked main part only when it holds a fair share of the page's text.
                if (\core_text::strlen($main) >= 0.3 * \core_text::strlen($all)) {
                    return [$title, $main];
                }
            }
        }
        return [$title, $all];
    }

    /**
     * The text under a node, with a new line where the page starts a new block.
     *
     * @param \DOMNode $node
     * @return string
     */
    private static function node_text(\DOMNode $node): string {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $out .= $child->nodeValue;
            } else if ($child instanceof \DOMElement) {
                $tag = strtolower($child->nodeName);
                if ($tag === 'br') {
                    $out .= "\n";
                } else if (in_array($tag, self::BLOCKS, true)) {
                    $out .= "\n" . self::node_text($child) . "\n";
                } else if ($tag === 'td' || $tag === 'th') {
                    $out .= self::node_text($child) . ' | ';
                } else {
                    $out .= self::node_text($child);
                }
            }
        }
        return $out;
    }

    /**
     * Text with its spare spaces and empty lines taken out.
     *
     * @param string $text
     * @return string
     */
    private static function tidy_text(string $text): string {
        $text = str_replace(["\r", "\u{00A0}", "\u{200B}"], ['', ' ', ''], $text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ ?\n ?/', "\n", $text);
        $text = preg_replace('/( \|)+ ?\n/', "\n", $text);
        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }

    /**
     * A title as one clean line.
     *
     * @param string $text
     * @return string
     */
    private static function one_line(string $text): string {
        return \core_text::substr(trim(preg_replace('/\s+/u', ' ', fix_utf8($text)) ?? ''), 0, 150);
    }
}
