<?php

namespace Starsquare\Letterboxd;

use Generator;
use Eluceo\iCal\Domain\Entity\Calendar as BaseCalendar;
use Eluceo\iCal\Presentation\Component;
use Eluceo\iCal\Presentation\Component\Property;
use Eluceo\iCal\Presentation\Component\Property\Value\TextValue;
use Eluceo\iCal\Presentation\Factory\CalendarFactory as BaseCalendarFactory;

class CalendarFactory extends BaseCalendarFactory
{
    /**
     * Needs to be duplicated thanks to getProperties being private
     */
    public function createCalendar(BaseCalendar $calendar): Component
    {
        $components = $this->createCalendarComponents($calendar);
        $properties = iterator_to_array($this->getProperties($calendar), false);

        return new Component('VCALENDAR', $properties, $components);
    }

    /**
     * @return Generator<Property>
     */
    protected function getProperties(BaseCalendar $calendar): Generator
    {
        yield from parent::getProperties($calendar);

        $name = $calendar->getName();
        if ($name) {
            /* @see https://docs.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-oxcical/1da58449-b97e-46bd-b018-a1ce576f3e6d */
            yield new Property('X-WR-CALNAME', new TextValue($name));
        }

        $description = $calendar->getDescription();
        if ($description) {
            /* @see https://docs.microsoft.com/en-us/openspecs/exchange_server_protocols/ms-oxcical/9194db93-6de2-41b3-bebe-fc76a11e31e9 */
            yield new Property('X-WR-CALDESC', new TextValue($description));
        }
    }
}
