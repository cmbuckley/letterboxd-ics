<?php

namespace Starsquare\Letterboxd\Test;

use Starsquare\Letterboxd\CalendarRenderer;
use Starsquare\Letterboxd\Logger;
use Starsquare\Letterboxd\Exception as LetterboxdException;
use Buzz\Browser;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class CalendarRendererTest extends TestCase {
    protected $zipFile = 'test/etc/diary.zip';
    protected $logStream;
    protected $log;

    protected function setUp(): void {
        $this->logStream = fopen('/dev/null', 'w');
        $this->log = new Logger($this->logStream);
    }

    protected function tearDown(): void {
        if (file_exists($this->zipFile)) {
            unlink($this->zipFile);
        }

        if ($this->logStream) {
            fclose($this->logStream);
        }
    }

    protected function getResponse($raw) {
        if (is_file($raw)) {
            $raw = file_get_contents($raw);
        }

        $pos = (strpos($raw, "\n\n") ?: strlen($raw));
        $headers = explode("\n", substr($raw, 0, $pos));
        $body = (string) substr($raw, $pos + 2);
        $statusLine = array_shift($headers);

        $factory = new Psr17Factory;
        $response = $factory->createResponse(200)->withBody($factory->createStream($body));
        foreach ($headers as $header) {
            list($header, $value) = explode(':', $header, 2);
            $response = $response->withHeader($header, $value);
        }

        return $response;
    }

    protected function getExportResponse() {
        $zip = new \ZipArchive();
        $zip->open($this->zipFile, $zip::CREATE);
        $zip->addFile('test/etc/diary.csv', 'diary.csv');
        $zip->close();

        $factory = new Psr17Factory;
        $body = $factory->createStreamFromFile($this->zipFile);

        return $factory->createResponse(200)->withHeader('Content-Type', 'application/zip')->withBody($body);
    }


    protected function assertLogin($getResponse, $submitResponse = null) {
        $renderer = new CalendarRendererStub(array(
            'log' => $this->log,
            'auth' => array(
                'username' => 'foo',
                'password' => 'bar',
            ),
        ));

        $browser = $this->createMock(Browser::class);
        $renderer->setBrowser($browser);

        if (is_string($getResponse)) {
            $getResponse = [$this->getResponse($getResponse)];
        }

        $urls = ['https://letterboxd.com/', 'https://letterboxd.com/data/export/'];
        array_walk($getResponse, fn(&$v, $k) => $v = [$urls[$k], [], $v]);

        $browser->expects($this->exactly(count($getResponse)))
            ->method('get')
            ->willReturnMap($getResponse);

        if ($submitResponse) {
            $browser->expects($this->once())
                ->method('submitForm')
                ->with('https://letterboxd.com/user/login.do', array('__csrf' => 'DUMMY', 'username' => 'foo', 'password' => 'bar'), 'POST')
                ->willReturn($this->getResponse($submitResponse));
        }

        $renderer->loadEvents();
    }

    public function testMissingOptionsFile() {
        $this->expectException(LetterboxdException::class, 'Cannot find options file');
        new CalendarRenderer('/missing/file');
    }

    public function testNonJsonOptionsFile() {
        $this->expectException(LetterboxdException::class, 'Cannot parse options file as JSON');
        new CalendarRenderer('test/etc/diary.csv');
    }

    public function testBadOptions() {
        $this->expectException(LetterboxdException::class, 'Options must be array or path to options file');
        new CalendarRenderer(4);
    }

    public function testGetBrowser() {
        $renderer = new CalendarRenderer();

        $browser = $renderer->getBrowser();
        $this->assertInstanceof(Browser::class, $browser);
    }

    public function testMissingCredentials() {
        $renderer = new CalendarRenderer();

        $this->expectException(LetterboxdException::class, 'Missing username/password');
        $renderer->loadEvents();
    }

    public function testLogin() {
        $this->assertLogin([
            $this->getResponse('test/etc/http/home'),
            $this->getExportResponse()
        ], 'test/etc/http/login');
    }

    public function testMissingCsrfToken() {
        $this->expectException(LetterboxdException::class, 'Cannot find CSRF token');
        $this->assertLogin('HTTP/1.1 200 OK');
    }

    public function testLoginBadHttpResponse() {
        $this->expectException(LetterboxdException::class, 'Received HTTP 400');
        $this->assertLogin('test/etc/http/home', 'HTTP/1.1 400 Bad Request');
    }

    public function testLoginNotJson() {
        $this->expectException(LetterboxdException::class, 'Could not decode response as JSON');
        $this->assertLogin('test/etc/http/home', 'HTTP/1.1 200 OK');
    }

    public function testLoginError() {
        $this->expectException(LetterboxdException::class, 'Cannot log in: error message');
        $this->assertLogin('test/etc/http/home', 'test/etc/http/login-error');
    }

    public function testLoginBadExportHttpResponse() {
        $this->expectException(LetterboxdException::class, 'Cannot read export: Received HTTP 400');
        $this->assertLogin([
            $this->getResponse('test/etc/http/home'),
            $this->getResponse('HTTP/1.1 400 Bad Request')
        ], 'test/etc/http/login');
    }

    public function testLoginBadExportData() {
        $this->expectException(LetterboxdException::class, 'Cannot read export: Did not respond with a ZIP file');
        $this->assertLogin([
            $this->getResponse('test/etc/http/home'),
            $this->getResponse('HTTP/1.1 200 OK')
        ], 'test/etc/http/login');
    }

    public function testOutputFile() {
        $renderer = new CalendarRenderer(array(
            'version' => '1.2.3',
            'log' => $this->log,
            'calendar' => array(
                'name' => 'Test',
                'timezone' => 'UTC',
            ),
            'output' => array(
                'headers' => false,
            ),
            'file' => 'test/etc/diary.csv',
        ));

        $expected = trim(file_get_contents('test/etc/events.ics'));
        $this->assertStringMatchesFormat($expected, str_replace("\r", '', $renderer));
    }

    public function testMissingEventFile() {
        $renderer = new CalendarRenderer(array(
            'log' => $this->log,
            'file' => '/missing/file',
        ));

        $this->expectException(LetterboxdException::class, 'Cannot find event file');
        $renderer->loadEvents();
    }

    public function testInvalidZip() {
        $renderer = new CalendarRenderer(array(
            'log' => $this->log,
            'file' => 'zip://test/etc/diary.zip#missing.csv',
        ));

        $this->expectException(LetterboxdException::class, 'Cannot find event file');
        $renderer->loadEvents();
    }

    public function testOutputErrors() {
        $renderer = new CalendarRenderer(array(
            'log' => $this->log,
            'file' => '/missing/file',
            'output' => array(
                'errors' => true,
            ),
        ));

        $this->assertStringStartsWith('Cannot find event file', (string) $renderer);
    }

    public function testGetHeaders() {
        $renderer = new CalendarRenderer(array(
            'log' => $this->log,
            'calendar' => array(
                'name' => 'Test',
                'timezone' => 'UTC',
            ),
            'file' => 'test/etc/diary.csv',
        ));

        $headers = $renderer->getHeaders();

        $this->assertSame('Content-Type: text/calendar; charset=utf-8', $headers[0]);
        $this->assertSame('Cache-Control: no-cache, must-revalidate', $headers[1]);
        $this->assertSame('Expires: Sat, 29 Sep 1984 15:00:00 GMT', $headers[2]);
        $this->assertSame('Last-Modified: Sat, 29 Sep 1984 15:00:00 GMT', $headers[3]);
        $this->assertMatchesRegularExpression('/ETag: "[0-9a-f]{32}"/', $headers[4]);
    }
}

class CalendarRendererStub extends CalendarRenderer {
    public function setBrowser($browser) {
        $this->browser = $browser;
    }
}
