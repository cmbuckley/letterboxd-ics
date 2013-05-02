<?php

require '../vendor/autoload.php';

use Buzz\Browser;
use Buzz\Message\RequestInterface;
use Buzz\Util\CookieJar;

use Eluceo\iCal\Component\Calendar;
use Eluceo\iCal\Component\Event;

$version = 0.2;
$phpVersion = phpversion();
$buzzVersion = '0.0';

$prodId = "-//StarSquare//LETTERBOXD//$version//EN";
$calendar = new Calendar($prodId);
$calendar->setName('Films');
$calendar->setTimezone('Europe/London');

$options = parse_ini_file('../config.ini');
$composer = json_decode(file_get_contents('../composer.lock'))->packages;

foreach ($composer as $package) {
    if ($package->name == 'kriswallsmith/buzz') {
        $buzzVersion = substr($package->version, 1);
        break;
    }
}

$browser = new Browser();
$browser->getClient()->setCookieJar(new CookieJar);

$response = $browser->submit('http://letterboxd.com/user/login.do', $options, RequestInterface::METHOD_POST, array(
    'User-Agent' => "letterboxd-ics/$version (http://bux.re/letterboxd-ics) Buzz/$buzzVersion PHP/$phpVersion",
));

if ($response->isOk()) {
    $export = $browser->get('http://letterboxd.com/data/export/');

    if ($export->isOk() && strpos($export->getHeader('Content-Type'), 'application/zip') === 0) {
        $file = tempnam(sys_get_temp_dir(), "letterboxd-export");
        file_put_contents($file, $export->getContent());

        $diary = fopen("zip://$file#diary.csv", 'r');
        $headers = null;

        if ($diary !== false) {
            while (false !== ($row = fgetcsv($diary))) {
                if ($headers === null) {
                    $headers = $row;
                } else {
                    $row = array_combine($headers, $row);

                    $event = new Event;
                    $event->setDtStart(new DateTime($row['Date']));
                    $event->setDtEnd(new DateTime($row['Date']));
                    $event->setNoTime(true);
                    $event->setSummary($row['Name']);
                    $event->setUrl($row['Letterboxd URI']);

                    $calendar->addEvent($event);
                }
            }
        }
    }
}

$output = $calendar->render();
header('Content-Type: text/plain; charset=utf-8'); // text/calendar
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 29 Sep 1984 15:00:00 GMT');
header('Last-Modified: Sat, 29 Sep 1984 15:00:00 GMT');
header('ETag: "' . md5($output) . '"');
echo $output;
