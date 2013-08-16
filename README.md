letterboxd-ics is a package to render a [Letterboxd](http://letterboxd.com)
diary in iCalendar format.

# How to install (standalone)

If you want to use the standalone package, you can do the following:

```bash
git clone https://github.com/starsquare/letterboxd-ics.git
cd letterboxd-ics
curl -sS https://getcomposer.org/installer | php
php composer.phar install
```

Once you have the package and its dependencies, you should edit `config.json`
to contain your Letterboxd credentials, and point a Web server at the `public`
folder.

# How to install (Packagist)

Alternatively, letterboxd-ics is [available on Packagist](https://packagist.org/packages/starsquare/letterboxd-ics),
so it can be specified as a dependency using [Composer](http://getcomposer.org):

```json
{
    "require": {
        "starsquare/letterboxd-ics": "2.0.0"
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
    * **timezone**: Timezone of the calendar.
* **output**: Config for the output.
    * **headers**: Whether to send response headers.
    * **content-type**: Content-Type sent with the response. Defaults to
      `text/calendar`, but `text/plain` will work for most clients.

Alternatively, you can define your configuration options in a JSON-encoded
config file, and pass the file path to the `Calendar` object. An example is
provided in the `public` folder.
