<?php

declare(strict_types=1);

namespace App;

/** Canonical variables; codes match the `variables` table. */
enum Variable: string
{
    case Temperature = 'temperature_2m';
    case Precipitation = 'precipitation';
    case TemperatureMax = 'temperature_2m_max';
    case TemperatureMin = 'temperature_2m_min';
    case TemperatureMean = 'temperature_2m_mean';
    case PrecipitationSum = 'precipitation_sum';

    public function unit(): string
    {
        return $this->isPrecipitation() ? 'mm' : '°C';
    }

    /** Physical sanity bounds; values outside them are rejected. */
    public function isPlausible(float $value): bool
    {
        return $this->isPrecipitation() ? $value >= 0 && $value <= 1000 : $value >= -90 && $value <= 60;
    }

    private function isPrecipitation(): bool
    {
        return in_array($this, [self::Precipitation, self::PrecipitationSum], true);
    }
}
