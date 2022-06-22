<?php

namespace Starsquare\Letterboxd;

use Eluceo\iCal\Domain\Entity\Calendar as BaseCalendar;

class Calendar extends BaseCalendar
{
    protected string $name = '';
    protected string $description = '';

    public function getName(): string
    {
        return $this->name;
    }

    public function setName($name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription($description): self
    {
        $this->description = $description;
        return $this;
    }
}
