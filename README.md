letterboxd-ics is a basic script for exporting a [Letterboxd](http://letterboxd.com)
to iCalendar format.

# How to use

* Clone the repository:

```bash
git clone https://github.com/starsquare/letterboxd-ics.git
cd letterboxd-ics
curl -sS https://getcomposer.org/installer | php
php composer.phar install
```

* Edit `config.ini` to contain your Letterboxd credentials.
* Point a Web server at the `public` folder.
* Enjoy!

# To do

[Composer](http://getcomposer.org) support
