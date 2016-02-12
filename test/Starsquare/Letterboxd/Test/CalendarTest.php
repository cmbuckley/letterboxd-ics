<?php

namespace Starsquare\Letterboxd\Test;

use Starsquare\Letterboxd\Calendar;
use Starsquare\Letterboxd\Logger;
use Buzz\Message\Request;
use Buzz\Message\Response;

class CalendarTest extends \PHPUnit_Framework_TestCase {
    protected $zipFile = 'test/etc/diary.zip';
    protected $logStream;
    protected $log;

    protected function setUp() {
        $this->logStream = fopen('/dev/null', 'w');
        $this->log = new Logger($this->logStream);
    }

    protected function tearDown() {
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
        $response = new Response();
        $response->setHeaders(explode("\n", substr($raw, 0, $pos)));
        $response->setContent((string) substr($raw, $pos + 2));

        return $response;
    }

    protected function getExportResponse() {
        $zip = new \ZipArchive();
        $zip->open($this->zipFile, $zip::CREATE);
        $zip->addFile('test/etc/diary.csv', 'diary.csv');
        $zip->close();

        $response = new Response();
        $response->setHeaders(array('HTTP/1.1 200 OK', 'Content-Type: application/zip'));
        $response->setContent(file_get_contents($this->zipFile));

        return $response;
    }


    protected function assertLogin($getResponse, $submitResponse = null) {
        $calendar = new CalendarStub(array(
            'log' => $this->log,
            'auth' => array(
                'username' => 'foo',
                'password' => 'bar',
            ),
        ));

        $browser = $this->getMock('Buzz\\Browser');
        $calendar->setBrowser($browser);

        $multiGet = ($getResponse instanceof \PHPUnit_Framework_MockObject_Stub_ConsecutiveCalls);

        if (is_string($getResponse)) {
            $getResponse = $this->returnValue($this->getResponse($getResponse));
        }

        $browser->expects($multiGet ? $this->any() : $this->once())
            ->method('get')
            ->will($getResponse);

        if ($submitResponse) {
            $browser->expects($this->once())
                ->method('submit')
                ->with('http://letterboxd.com/user/login.do', array('__csrf' => 'DUMMY', 'username' => 'foo', 'password' => 'bar'), 'POST')
                ->will($this->returnValue($this->getResponse($submitResponse)));
        }

        $calendar->loadEvents();
    }

    public function testMissingOptionsFile() {
        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Cannot find options file');
        new Calendar('/missing/file');
    }

    public function testNonJsonOptionsFile() {
        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Cannot parse options file as JSON');
        new Calendar('test/etc/diary.csv');
    }

    public function testBadOptions() {
        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Options must be array or path to options file');
        new Calendar(4);
    }

    public function testGetBrowser() {
        $calendar = new Calendar();

        $browser = $calendar->getBrowser();
        $this->assertInstanceof('Buzz\\Browser', $browser);

        $listener = $browser->getListener();
        $this->assertInstanceof('Buzz\\Listener\\ListenerChain', $listener);

        $listeners = $listener->getListeners();
        $this->assertSame(2, count($listeners));
        $this->assertInstanceOf('Buzz\\Listener\\CallbackListener', $listeners[0]);
        $this->assertInstanceOf('Buzz\\Listener\\CookieListener',   $listeners[1]);

        $request = new Request();
        $listener->preSend($request);
        $headers = $request->getHeaders();
        $this->assertRegExp('#User-Agent: letterboxd-ics/[\d.]+ \(http://bux\.re/letterboxd-ics\) PHP/.*#', $headers[0]);
    }

    public function testMissingCredentials() {
        $calendar = new Calendar();

        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Missing username/password');
        $calendar->loadEvents();
    }

    public function testLogin() {
        $this->assertLogin($this->onConsecutiveCalls(
            $this->getResponse('test/etc/http/home'),
            $this->getExportResponse()
        ), 'test/etc/http/login');
    }

    public function testMissingCsrfToken() {
        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Cannot find CSRF token');
        $this->assertLogin('HTTP/1.1 200 OK');
    }

    public function testLoginBadHttpResponse() {
        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Received HTTP 400');
        $this->assertLogin('test/etc/http/home', 'HTTP/1.1 400 Bad Request');
    }

    public function testLoginNotJson() {
        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Could not decode response as JSON');
        $this->assertLogin('test/etc/http/home', 'HTTP/1.1 200 OK');
    }

    public function testLoginError() {
        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Cannot log in: error message');
        $this->assertLogin('test/etc/http/home', 'test/etc/http/login-error');
    }

    public function testLoginBadExportHttpResponse() {
        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Cannot read export: Received HTTP 400');
        $this->assertLogin($this->onConsecutiveCalls(
            $this->getResponse('test/etc/http/home'),
            $this->getResponse('HTTP/1.1 400 Bad Request')
        ), 'test/etc/http/login');
    }

    public function testLoginBadExportData() {
        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Cannot read export: Did not respond with a ZIP file');
        $this->assertLogin($this->onConsecutiveCalls(
            $this->getResponse('test/etc/http/home'),
            $this->getResponse('HTTP/1.1 200 OK')
        ), 'test/etc/http/login');
    }

    public function testOutputFile() {
        $calendar = new Calendar(array(
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
        $this->assertStringMatchesFormat($expected, str_replace("\r", '', $calendar));
    }

    public function testMissingEventFile() {
        $calendar = new Calendar(array(
            'log' => $this->log,
            'file' => '/missing/file',
        ));

        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Cannot find event file');
        $calendar->loadEvents();
    }

    public function testInvalidZip() {
        $calendar = new Calendar(array(
            'log' => $this->log,
            'file' => 'zip://test/etc/diary.zip#missing.csv',
        ));

        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Cannot find event file');
        $calendar->loadEvents();
    }

    public function testOutputErrors() {
        $calendar = new Calendar(array(
            'log' => $this->log,
            'file' => '/missing/file',
            'output' => array(
                'errors' => true,
            ),
        ));

        $this->assertStringStartsWith('Cannot find event file', (string) $calendar);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSendHeaders() {
        if (!function_exists('xdebug_get_headers')) {
            $this->markTestSkipped('Needs Xdebug to retrieve headers');
        }

        $calendar = new Calendar(array(
            'log' => $this->log,
            'calendar' => array(
                'name' => 'Test',
                'timezone' => 'UTC',
            ),
            'file' => 'test/etc/diary.csv',
        ));

        $calendar->sendHeaders();
        $headers = xdebug_get_headers();

        $this->assertSame('Content-Type: text/calendar; charset=utf-8', $headers[0]);
        $this->assertSame('Cache-Control: no-cache, must-revalidate', $headers[1]);
        $this->assertSame('Expires: Sat, 29 Sep 1984 15:00:00 GMT', $headers[2]);
        $this->assertSame('Last-Modified: Sat, 29 Sep 1984 15:00:00 GMT', $headers[3]);
        $this->assertRegExp('/ETag: "[0-9a-f]{32}"/', $headers[4]);
    }
}

class CalendarStub extends Calendar {
    public function setBrowser($browser) {
        $this->browser = $browser;
    }
}
