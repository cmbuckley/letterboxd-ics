<?php

namespace Starsquare\Letterboxd;

use Buzz\Browser;
use Buzz\Client\Curl;
use Nyholm\Psr7\Factory\Psr17Factory;
use Buzz\Middleware\CallbackMiddleware;
use Buzz\Middleware\CookieMiddleware;
use Buzz\Message\RequestInterface;

use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\Entity\TimeZone;
use Eluceo\iCal\Domain\ValueObject\Date;
use Eluceo\iCal\Domain\ValueObject\SingleDay;
use Eluceo\iCal\Domain\ValueObject\Uri;

class CalendarRenderer {
    const PROD_ID = '-//StarSquare//LETTERBOXD//%s//EN';
    const USER_AGENT = 'letterboxd-ics/%s (https://bux.re/letterboxd-ics) PHP/%s';
    const CSRF_TOKEN = '__csrf';
    const CSRF_PATTERN = '/CSRF = ("|\')(?P<token>.+?)\1/';

    protected $urls = array(
        'home'   => 'https://letterboxd.com/',
        'login'  => 'https://letterboxd.com/user/login.do',
        'export' => 'https://letterboxd.com/data/export/',
    );

    /**
     * Name of headers to use from the CSV
     */
    protected $headers = array(
        'summary' => 'Name',
        'date'    => 'Watched Date',
        'url'     => 'Letterboxd URI',
        'rating'  => 'Rating',
        'year'    => 'Year',
    );

    /**
     * Calendar options
     */
    protected $options = array(
        'version' => 'x.x.x',
        'auth'    => array(),
        'output'  => array(
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

    /**
     * Logger
     */
    protected $log = null;

    /**
     * Calendar
     */
    protected $calendar = null;

    public function __construct($options = array()) {
        $this->setOptions($options);

        $this->calendar = new Calendar;
        $this->calendar->setProductIdentifier(sprintf(static::PROD_ID, $this->options['version']));

        if (!isset($this->options['log'])) {
            $this->options['log'] = new Logger('php://stderr');
        }

        $this->log = $this->options['log'];

        if (isset($this->options['calendar']['name'])) {
            $this->calendar->setName($this->options['calendar']['name']);
        }

        if (isset($this->options['calendar']['description'])) {
            $this->calendar->setDescription($this->options['calendar']['description']);
        }

        if (isset($this->options['calendar']['timezone'])) {
            $this->calendar->addTimezone(new TimeZone($this->options['calendar']['timezone']));
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
                $this->options['version'],
                phpversion()
            );
        }

        return $this->userAgent;
    }

    public function getBrowser() {
        if ($this->browser === null) {
            $this->browser = new Browser(new Curl(new Psr17Factory), new Psr17Factory);

            $this->browser->addMiddleware(new CallbackMiddleware(function ($request, $response = null) {
                if ($response) {
                    return $response;
                }

                return $request->withHeader('User-Agent', $this->getUserAgent());
            }));

            $this->browser->addMiddleware(new CookieMiddleware);
        }

        return $this->browser;
    }

    protected function login() {
        $auth = $this->options['auth'];

        if (empty($auth['username']) || empty($auth['password'])) {
            throw new Exception('Cannot log in: Missing username/password');
        }

        $browser = $this->getBrowser();
        $home = $browser->get($this->urls['home']);
        $content = (string) $home->getBody();

        if (!preg_match(sprintf(static::CSRF_PATTERN, static::CSRF_TOKEN), $content, $matches)) {
            throw new Exception('Cannot log in: Cannot find CSRF token');
        }

        $this->log->info('Logging in');

        $auth[static::CSRF_TOKEN] = $matches['token'];
        $loginResponse = $browser->submitForm($this->urls['login'], $auth);

        if ($loginResponse->getStatusCode() != 200) {
            $this->log->warn('Login HTTP Error ' . $loginResponse->getStatusCode());
            throw new Exception('Cannot log in: Received HTTP ' . $loginResponse->getStatusCode());
        }

        if (($result = json_decode((string) $loginResponse->getBody())) === null) {
            throw new Exception('Cannot log in: Could not decode response as JSON');
        }

        if ($result->result === 'error') {
            $this->log->warn('Login error: ' . $result->messages[0]);
            throw new Exception('Cannot log in: ' . $result->messages[0]);
        }

        $this->log->notice('Login completed');
    }

    protected function getFile() {
        if ($this->file === null) {
            if (isset($this->options['file'])) {
                $this->file = $this->options['file'];
            } else {
                $this->login();

                $this->log->info('Getting export file: ' . $this->urls['export']);
                $export = $this->getBrowser()->get($this->urls['export']);

                if ($export->getStatusCode() !== 200) {
                    throw new Exception('Cannot read export: Received HTTP ' . $export->getStatusCode());
                }

                $contentType = $export->getHeader('Content-Type');
                if (strpos($contentType[0], 'application/zip') === false) {
                    throw new Exception('Cannot read export: Did not respond with a ZIP file');
                }

                $this->log->info('Creating local export ZIP');
                $zipFile = tempnam(sys_get_temp_dir(), 'letterboxd-export');
                file_put_contents($zipFile, $export->getBody());
                $this->file = "zip://$zipFile#diary.csv";
            }
        }

        return $this->file;
    }

    public function loadEvents() {
        if (!iterator_count($this->calendar->getEvents())) {
            $diary = @fopen($this->getFile(), 'r');
            $headers = null;

            if ($diary === false) {
                $error = error_get_last();
                throw new Exception("Cannot find event file: " . $error['message']);
            }

            $this->log->info('Parsing diary CSV');

            while (false !== ($row = fgetcsv($diary))) {
                if ($headers === null) {
                    $headers = $row;
                } else {
                    $row = array_combine($headers, $row);
                    $date = new \DateTime($row[$this->headers['date']]);

                    $event = (new Event)
                        ->setSummary($row[$this->headers['summary']])
                        ->setDescription($this->getEventDescription($row))
                        ->setOccurrence(new SingleDay(new Date($date)))
                        ->setUrl(new Uri($row[$this->headers['url']]));

                    $this->calendar->addEvent($event);
                }
            }

            $this->log->info('Parsing complete');
        }
    }

    protected function getEventDescription(array $data) {
       $template = implode("\n", [
           'Year: %d',
           'Rating: %s',
       ]);

       $year       = $data[$this->headers['year']];
       $rating     = $data[$this->headers['rating']];
       $ratingText = str_repeat('★', intval($rating)) . (strpos($rating, '.5') ? '½' : '');

       return sprintf($template, $year, $ratingText);
    }

    public function sendHeaders() {
        if ($this->options['output']['headers'] && !headers_sent()) {
            header(sprintf(
                'Content-Type: %s; charset=%s',
                $this->options['output']['content-type'],
                $this->options['output']['charset']
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
            $calendarComponent = (new CalendarFactory())->createCalendar($this->calendar);
            $this->output = (string) $calendarComponent;
        }

        $this->sendHeaders();
        return $this->output;
    }

    public function __toString() {
        try {
            return $this->render();
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
