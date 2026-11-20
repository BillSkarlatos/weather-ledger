<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;

final readonly class ForecastPoint
{
    public DateTimeImmutable $validAt;
    public string $localDate;

    /** @param DateTimeImmutable $localTime provider wall-clock time; stored as a UTC instant plus local date */
    public function __construct(public Variable $variable, DateTimeImmutable $localTime, public float $value)
    {
        $this->validAt = $localTime->setTimezone(new DateTimeZone('UTC'));
        $this->localDate = $localTime->format('Y-m-d');
    }
}
