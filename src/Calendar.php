<?php

namespace Starsquare\Letterboxd;

use Buzz\Browser;
use Buzz\Listener\CallbackListener;
use Buzz\Listener\CookieListener;
use Buzz\Message\RequestInterface;

use Eluceo\iCal\Component\Calendar as BaseCalendar;
use Eluceo\iCal\Component\Event;

class Calendar extends BaseCalendar {
    const VERSION = '3.1.1';
    const PROD_ID = '-//StarSquare//LETTERBOXD//%s//EN';
    const USER_AGENT = 'letterboxd-ics/%s (http://bux.re/letterboxd-ics) PHP/%s';
    const CSRF_TOKEN = '__csrf';
    const CSRF_PATTERN = '/CSRF = "(?P<token>[^"]+)"/';

    protected $urls = array(
        'home'   => 'http://letterboxd.com/',
        'login'  => 'http://letterboxd.com/user/login.do',
        'export' => 'http://letterboxd.com/data/export/',
    );

    /**
     * Name of headers to use from the CSV
     */
    protected $headers = array(
        'summary' => 'Name',
        'date'    => 'Watched Date',
        'url'     => 'Letterboxd URI',
    );

    /**
     * Calendar options
     */
    protected $options = array(
        'auth'   => array(),
        'output' => array(
            'headers'      => true,
            'errors'       => false,
            'content-type' => 'text/calendar',
            'charset'      => 'utf-8',
        ),
    );

    /**
     * Rendered output
     */
    protected $output = null;

    /**
     * HTTP client
     */
    protected $browser = null;

    /**
     * User-Agent to use with HTTP client
     */
    protected $userAgent = null;

    /**
     * Path to CSV file
     */
    protected $file = null;

    public function __construct($options = array()) {
        parent::__construct(sprintf(static::PROD_ID, static::VERSION));
        $this->setOptions($options);

        if (isset($this->options['calendar']['name'])) {
            $this->setName($this->options['calendar']['name']);
        }

        if (isset($this->options['calendar']['timezone'])) {
            $this->setTimezone($this->options['calendar']['timezone']);
        }
    }

    public function setOptions($options) {
        if (is_string($options)) {
            if (!file_exists($options)) {
                throw new Exception("Cannot find options file: $options");
            }

            $options = json_decode(file_get_contents($options), true);

            if ($options === null) {
                throw new Exception('Cannot parse options file as JSON');
            }
        }

        if (!is_array($options)) {
            throw new Exception('Options must be array or path to options file');
        }

        $this->options = array_replace_recursive($this->options, $options);
        return $this;
    }

    protected function getUserAgent() {
        if ($this->userAgent === null) {
            $this->userAgent = sprintf(
                static::USER_AGENT,
                static::VERSION,
                phpversion()
            );
        }

        return $this->userAgent;
    }

    public function getBrowser() {
        if ($this->browser === null) {
            $this->browser = new Browser();
            $headers = array(
                'User-Agent' => $this->getUserAgent(),
            );

            $this->browser->addListener(new CallbackListener(function ($request, $response = null) use ($headers) {
                if (!$response) {
                    $request->addHeaders($headers);
                }
            }));

            $this->browser->addListener(new CookieListener);
        }

        return $this->browser;
    }

    protected function login() {
        $browser = $this->getBrowser();
        $home = $browser->get($this->urls['home']);
        $content = $home->getContent();

        if (!preg_match(sprintf(static::CSRF_PATTERN, static::CSRF_TOKEN), $content, $matches)) {
            throw new Exception('Cannot log in: Cannot find CSRF token');
        }

        $auth = $this->options['auth'];
        $auth[static::CSRF_TOKEN] = $matches['token'];

        $loginResponse = $browser->submit($this->urls['login'], $auth, RequestInterface::METHOD_POST);

        if (!$loginResponse->isOk()) {
            throw new Exception('Cannot log in: Received HTTP ' . $loginResponse->getStatusCode());
        }

        if (($result = json_decode($loginResponse->getContent())) === null) {
            throw new Exception('Cannot log in: Could not decode response as JSON');
        }

        if ($result->result === 'error') {
            throw new Exception('Cannot log in: ' . $result->messages[0]);
        }
    }

    protected function getFile() {
        if ($this->file === null) {
            if (isset($this->options['file'])) {
                $this->file = $this->options['file'];
            } else {
                $this->login();

                $export = $this->getBrowser()->get($this->urls['export']);

                if (!$export->isOk()) {
                    throw new Exception('Cannot read export: Received HTTP ' . $export->getStatusCode());
                }

                if (strpos($export->getHeader('Content-Type'), 'application/zip') === false) {
                    throw new Exception('Cannot read export: Did not respond with a ZIP file');
                }

                $zipFile = tempnam(sys_get_temp_dir(), 'letterboxd-export');
                file_put_contents($zipFile, $export->getContent());
                $this->file = "zip://$zipFile#diary.csv";
            }
        }

        return $this->file;
    }

    public function loadEvents() {
        if ($this->components === array()) {
            $diary = @fopen($this->getFile(), 'r');
            $headers = null;

            if ($diary === false) {
                $error = error_get_last();
                throw new Exception("Cannot find event file: " . $error['message']);
            }

            while (false !== ($row = fgetcsv($diary))) {
                if ($headers === null) {
                    $headers = $row;
                } else {
                    $row = array_combine($headers, $row);

                    $event = new Event;
                    $event->setDtStart(new \DateTime($row[$this->headers['date']]));
                    $event->setDtEnd(new \DateTime($row[$this->headers['date']]));
                    $event->setNoTime(true);
                    $event->setSummary($row[$this->headers['summary']]);
                    $event->setUrl($row[$this->headers['url']]);

                    $this->addEvent($event);
                }
            }
        }
    }

    public function sendHeaders() {
        if ($this->options['output']['headers'] && !headers_sent()) {
            header(sprintf(
                'Content-Type: %s; charset=utf-8',
                $this->options['output']['content-type']
            ));
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: Sat, 29 Sep 1984 15:00:00 GMT');
            header('Last-Modified: Sat, 29 Sep 1984 15:00:00 GMT');
            header('ETag: "' . md5($this->output) . '"');
        }
    }

    public function render() {
        $this->loadEvents();

        if ($this->output === null) {
            $this->output = parent::render();
        }

        $this->sendHeaders();
        return $this->output;
    }

    public function __toString() {
        try {
            return parent::__toString();
        } catch (\Exception $ex) {
            $message = $ex->getMessage();

            if ($this->options['output']['errors']) {
                return $message;
            } else {
                trigger_error($message, E_USER_ERROR);
                return '';
            }
        }
    }
}
