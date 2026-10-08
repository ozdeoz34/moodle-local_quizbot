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

namespace local_quizbot;

use local_quizbot\local\link_reader;

/**
 * Tests for the reader of a link a teacher gives as a source. Nothing here opens an address on the internet: what is
 * tested is what is refused before any request, and how a page's HTML becomes text.
 *
 * @package    local_quizbot
 * @category   test
 * @copyright  2026 Capstone Edu Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_quizbot\local\link_reader
 */
final class link_reader_test extends \advanced_testcase {
    /**
     * What is not a public web address is refused before anything is fetched.
     *
     * @dataProvider refused_provider
     * @param string $typed
     * @param string $errorcode
     */
    public function test_refused_without_a_request(string $typed, string $errorcode): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        try {
            link_reader::read($typed, \context_system::instance(), 1);
            $this->fail('The link was not refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * Cases for test_refused_without_a_request.
     *
     * @return array
     */
    public static function refused_provider(): array {
        return [
            'words, not a link' => ['photosynthesis notes', 'linkerror_invalid'],
            'another protocol' => ['ftp://files.example.org/notes.txt', 'linkerror_invalid'],
            'a local file' => ['file:///etc/passwd', 'linkerror_invalid'],
            'a host without a dot' => ['http://localhost:3306/', 'linkerror_invalid'],
            'this Moodle site itself' => ['https://www.example.com/moodle/course/view.php?id=2', 'linkerror_ownsite'],
            'a YouTube playlist' => ['https://www.youtube.com/playlist?list=PL590L5WQmH8fJ54F369BLDSqIwcs-TCfs',
                'linkerror_youtube'],
            'the loopback address' => ['http://127.0.0.1/', 'linkerror_blocked'],
            'the cloud metadata address' => ['http://169.254.169.254/latest/meta-data/', 'linkerror_blocked'],
            'a private address' => ['http://192.168.1.10/intranet/notes.html', 'linkerror_blocked'],
            'another private address, with a port' => ['http://10.0.0.5:8080/', 'linkerror_blocked'],
            'the loopback address in IPv6' => ['http://[::1]/', 'linkerror_invalid'],
        ];
    }

    /**
     * Inside addresses stay refused when the site's own list of blocked hosts is empty: the plugin's rule does not
     * depend on a setting. (Moodle's test environment starts with that list empty - which is how this was found.)
     */
    public function test_inside_addresses_are_refused_whatever_the_settings(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        foreach (['http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://172.16.4.4/'] as $address) {
            try {
                link_reader::read($address, \context_system::instance(), 1);
                $this->fail("$address was opened.");
            } catch (\moodle_exception $e) {
                $this->assertSame('linkerror_blocked', $e->errorcode, $address);
            }
        }
    }

    /**
     * A page's menus, scripts and footer are left out; its main part and its title are kept.
     */
    public function test_page_text_keeps_the_content(): void {
        $html = '<html><head><title>Water cycle - School site</title><style>p { color: red }</style></head><body>'
            . '<nav><a href="/">Home</a> <a href="/about">About us</a></nav>'
            . '<main><h1>The water cycle</h1><p>Heat from the Sun makes water <b>evaporate</b>.</p>'
            . '<ul><li>Evaporation</li><li>Condensation</li></ul><script>track("page");</script>'
            . '<table><tr><th>Stage</th><th>Where</th></tr><tr><td>Rain</td><td>Clouds</td></tr></table></main>'
            . '<footer>Copyright School site. Cookie settings.</footer></body></html>';
        $method = new \ReflectionMethod(link_reader::class, 'page_text');
        $method->setAccessible(true);
        [$title, $text] = $method->invoke(null, $html);

        $this->assertSame('Water cycle - School site', $title);
        $this->assertStringContainsString('The water cycle', $text);
        $this->assertStringContainsString('Heat from the Sun makes water evaporate.', $text);
        $this->assertMatchesRegularExpression('/Evaporation\n+Condensation/', $text, 'List items on lines of their own.');
        $this->assertStringContainsString('Rain | Clouds', $text);
        $this->assertStringNotContainsString('About us', $text);
        $this->assertStringNotContainsString('track(', $text);
        $this->assertStringNotContainsString('Cookie settings', $text);
        $this->assertStringNotContainsString('color: red', $text);
    }

    /**
     * A page written in an older character set arrives as proper UTF-8.
     */
    public function test_other_character_sets(): void {
        $method = new \ReflectionMethod(link_reader::class, 'to_utf8');
        $method->setAccessible(true);
        $latin = \core_text::convert('Çağdaş café – fenêtre', 'utf-8', 'windows-1254');
        $this->assertSame('Çağdaş café – fenêtre', $method->invoke(null, $latin, 'text/html; charset=windows-1254'));
        $this->assertSame('déjà vu', $method->invoke(null, \core_text::convert('déjà vu', 'utf-8', 'iso-8859-1'), 'text/html'));
        $this->assertSame('Türkçe ✓', $method->invoke(null, 'Türkçe ✓', 'text/html; charset=UTF-8'));
    }
}
