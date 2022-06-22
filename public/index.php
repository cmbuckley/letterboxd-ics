<?php

require '../vendor/autoload.php';

echo new Starsquare\Letterboxd\CalendarRenderer(array(
    'version' => \Composer\InstalledVersions::getRootPackage()['pretty_version'],
    'log' => new Starsquare\Letterboxd\Logger('php://stderr'),
    'auth' => array(
        'username' => getenv('LETTERBOXD_USERNAME'),
        'password' => getenv('LETTERBOXD_PASSWORD'),
    ),
    'calendar' => array(
        'name'        => 'Films',
        'description' => 'Calendar for films logged in Letterboxd',
        'timezone'    => 'Europe/London',
    ),
    'output' => array(
        'errors'       => true,
        'content-type' => 'text/plain',
    ),
));
