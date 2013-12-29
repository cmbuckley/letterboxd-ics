<?php

namespace Starsquare\Letterboxd\Test;

use Starsquare\Letterboxd\Calendar;
use Buzz\Message\Response;

class CalendarTest extends \PHPUnit_Framework_TestCase {
    protected $zipFile = 'test/etc/diary.zip';

    protected function tearDown() {
        if (file_exists($this->zipFile)) {
            unlink($this->zipFile);
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
        $calendar = new CalendarStub();

        $browser = $this->getMock('Starsquare\\Letterboxd\\Browser');
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
                ->with('http://letterboxd.com/user/login.do', array('__csrf' => 'DUMMY'), 'POST')
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
            'file' => '/missing/file',
        ));

        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'Cannot find event file');
        $calendar->loadEvents();
    }

    public function testInvalidZip() {
        $calendar = new Calendar(array(
            'file' => 'zip://test/etc/diary.zip#missing.csv',
        ));

        $this->setExpectedException('Starsquare\\Letterboxd\\Exception', 'operation failed');
        $calendar->loadEvents();
    }
}

class CalendarStub extends Calendar {
    public function setBrowser($browser) {
        $this->browser = $browser;
    }
}