letterboxd-ics is a package to render a [Letterboxd](http://letterboxd.com)
diary in iCalendar format.

[![Build Status](https://travis-ci.org/cmbuckley/letterboxd-ics.png)](https://travis-ci.org/cmbuckley/letterboxd-ics)

# How to install (standalone)

If you want to use the standalone package, you can do the following:

```bash
git clone https://github.com/cmbuckley/letterboxd-ics.git
cd letterboxd-ics
composer install
```

Once you have the package and its dependencies, set environment variables
`LETTERBOXD_USERNAME` and `LETTERBOXD_PASSWORD` to your credentials, then point
a Web server at the `public` folder.

# How to install (Heroku)

Fork the repository and update `.travis.yml`:

```bash
heroku apps:create my-custom-app-name
travis encrypt $(heroku auth:token) --add deploy.api_key
sed -i'' 's/letterboxd-ical/my-custom-app-name/' .travis.yml
```

# How to install (Packagist)

Alternatively, letterboxd-ics is [available on Packagist](https://packagist.org/packages/cmbuckley/letterboxd-ics),
so it can be specified as a dependency using [Composer](http://getcomposer.org):

```json
{
    "require": {
        "cmbuckley/letterboxd-ics": "~3.0"
    }
}
```

# How to use

You can specify your own options:

```php
<?php
require 'vendor/autoload.php';

$calendar = new Starsquare\Letterboxd\Calendar(array(
    'auth' => array(
        'username' => 'user@example.com',
        'password' => 'password',
    ),
));

echo $calendar;
```

Other configuration options:

* **calendar**: Config for the calendar.
    * **name**: Name of the calendar.
    * **description**: Full description of the calendar.
    * **timezone**: Timezone of the calendar.
* **output**: Config for the output.
    * **headers**: Whether to send response headers.
    * **errors**: Whether to display errors.
    * **content-type**: Content-Type sent with the response. Defaults to
      `text/calendar`, but `text/plain` will work for most clients.
    * **charset**: Character set sent with the response.

Alternatively, you can define your configuration options in a JSON-encoded
config file, and pass the file path to the `Calendar` object. An example is
provided in the `public` folder.
