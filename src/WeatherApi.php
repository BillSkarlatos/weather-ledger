<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;

/** https://www.weatherapi.com/docs/ */
final class WeatherApi extends Provider
{
    private const HOURLY = ['temp_c' => Variable::Temperature, 'precip_mm' => Variable::Precipitation];
    private const DAILY = [
        'maxtemp_c' => Variable::TemperatureMax,
        'mintemp_c' => Variable::TemperatureMin,
        'avgtemp_c' => Variable::TemperatureMean,
        'totalprecip_mm' => Variable::PrecipitationSum,
    ];

    /** @param int $maxDays forecast days allowed by the plan (Free: 3) */
    public function __construct(private readonly string $apiKey, private readonly int $maxDays)
    {
    }

    public function code(): string
    {
        return 'weatherapi';
    }

    public function url(Location $location, int $days): string
    {
        return 'https://api.weatherapi.com/v1/forecast.json?' . http_build_query([
            'key' => $this->apiKey,
            'q' => "{$location->latitude},{$location->longitude}",
            'days' => min($days, $this->maxDays),
        ]);
    }

    public function parse(array $payload): array
    {
        $zone = new DateTimeZone(self::field($payload, 'location.tz_id'));

        $points = [];
        foreach (self::list($payload, 'forecast.forecastday') as $day) {
            $midnight = new DateTimeImmutable(self::field($day, 'date'), $zone);
            foreach (self::DAILY as $field => $variable) {
                $points[] = new ForecastPoint($variable, $midnight, self::number($day, "day.{$field}"));
            }
            foreach (self::list($day, 'hour') as $hour) {
                $time = (new DateTimeImmutable('@' . self::number($hour, 'time_epoch')))->setTimezone($zone);
                foreach (self::HOURLY as $field => $variable) {
                    $points[] = new ForecastPoint($variable, $time, self::number($hour, $field));
                }
            }
        }

        return $points;
    }
}
