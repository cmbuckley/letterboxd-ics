<?php

require '../vendor/autoload.php';

echo new Starsquare\Letterboxd\Calendar(array(
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
