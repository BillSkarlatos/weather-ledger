<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

/** https://open-meteo.com/en/docs */
final class OpenMeteo extends Provider
{
    private const FIELDS = [
        'hourly' => ['temperature_2m' => Variable::Temperature, 'precipitation' => Variable::Precipitation],
        'daily' => [
            'temperature_2m_max' => Variable::TemperatureMax,
            'temperature_2m_min' => Variable::TemperatureMin,
            'temperature_2m_mean' => Variable::TemperatureMean,
            'precipitation_sum' => Variable::PrecipitationSum,
        ],
    ];

    /** The key is only needed for the commercial endpoint (customer-api.open-meteo.com). */
    public function __construct(private readonly string $baseUrl, private readonly string $apiKey = '')
    {
    }

    public function code(): string
    {
        return 'open_meteo';
    }

    public function url(Location $location, int $days): string
    {
        return "{$this->baseUrl}/v1/forecast?" . http_build_query(array_filter([
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'timezone' => $location->timezone,
            'hourly' => implode(',', array_keys(self::FIELDS['hourly'])),
            'daily' => implode(',', array_keys(self::FIELDS['daily'])),
            'forecast_days' => min($days, 16),
            'apikey' => $this->apiKey,
        ]));
    }

    public function parse(array $payload): array
    {
        // Local times in a response all share one fixed UTC offset.
        $offset = (int) self::number($payload, 'utc_offset_seconds');
        $zone = new DateTimeZone(sprintf('%s%02d:%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 3600), abs($offset) % 3600 / 60));

        $points = [];
        foreach (self::FIELDS as $block => $fields) {
            $times = self::list($payload, "{$block}.time");
            foreach ($fields as $field => $variable) {
                $unit = self::field($payload, "{$block}_units.{$field}");
                if ($unit !== $variable->unit()) {
                    throw new UnexpectedValueException("Unexpected unit '{$unit}' for {$field}");
                }
                foreach (self::list($payload, "{$block}.{$field}") as $i => $value) {
                    if ($value === null) {
                        continue;
                    }
                    $time = new DateTimeImmutable($times[$i] ?? throw new UnexpectedValueException("No time for {$field}[{$i}]"), $zone);
                    // Hourly precipitation is labelled with the END of its hour; store the start.
                    $points[] = new ForecastPoint($variable, $variable === Variable::Precipitation ? $time->modify('-1 hour') : $time, $value);
                }
            }
        }

        return $points;
    }
}
