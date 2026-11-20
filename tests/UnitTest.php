<?php

declare(strict_types=1);

use App\ForecastPoint;
use App\Location;
use App\OpenMeteo;
use App\Scheduler;
use App\Variable;
use App\WeatherApi;

$berlin = new Location(1, 'Berlin', 52.52, 13.41, 'Europe/Berlin');
$query = static function (string $url): array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    return $query;
};
$point = static fn(array $points, Variable $v, string $utc): ?ForecastPoint =>
    array_values(array_filter($points, static fn(ForecastPoint $p) => $p->variable === $v && $p->validAt->format('Y-m-d\TH:i') === $utc))[0] ?? null;
$scheduler = static function (array &$calls, array $results): Scheduler {
    return new Scheduler(static function (string $date) use (&$calls, &$results): bool {
        $calls[] = $date;
        $result = array_shift($results) ?? true;
        return $result instanceof Throwable ? throw $result : $result;
    }, '06:00', 30);
};
$at = static fn(string $time) => new DateTimeImmutable($time);

return [
    'Open-Meteo URL: both resolutions, days capped at 16, no key on free API' => static function () use ($berlin, $query): void {
        $q = $query((new OpenMeteo('https://api.open-meteo.com'))->url($berlin, 30));
        check(['temperature_2m,precipitation', '16', 'Europe/Berlin', false], [$q['hourly'], $q['forecast_days'], $q['timezone'], isset($q['apikey'])]);
    },

    'Open-Meteo parse: UTC instants, local dates, nulls skipped, rain shifted to hour start' => static function () use ($point): void {
        $points = (new OpenMeteo('x'))->parse(json_decode(fixture('open_meteo'), true));
        check(9, count($points), 'points');
        check('2026-11-19', $point($points, Variable::Temperature, '2026-11-18T23:00')?->localDate);
        check(0.4, $point($points, Variable::Precipitation, '2026-11-18T23:00')?->value, 'rain labelled 01:00 local');
        check(7.0, $point($points, Variable::TemperatureMax, '2026-11-18T23:00')?->value, 'daily at local midnight');
    },

    'Open-Meteo parse: unexpected unit is rejected' => static function (): void {
        $payload = json_decode(fixture('open_meteo'), true);
        $payload['hourly_units']['temperature_2m'] = '°F';
        check(true, fails(static fn() => (new OpenMeteo('x'))->parse($payload)));
    },

    'WeatherAPI URL: days capped by plan' => static function () use ($berlin, $query): void {
        $q = $query((new WeatherApi('k', 3))->url($berlin, 7));
        check(['3', '52.52,13.41'], [$q['days'], $q['q']]);
    },

    'WeatherAPI parse: hourly and daily fields, missing field rejected' => static function () use ($point): void {
        $payload = json_decode(fixture('weatherapi'), true);
        $points = (new WeatherApi('k', 3))->parse($payload);
        check(8, count($points), 'points');
        check(1.2, $point($points, Variable::PrecipitationSum, '2026-11-18T23:00')?->value);
        unset($payload['forecast']['forecastday'][0]['day']['avgtemp_c']);
        check(true, fails(static fn() => (new WeatherApi('k', 3))->parse($payload)));
    },

    'Variable: implausible values are recognised' => static function (): void {
        check([false, false, true], [Variable::Temperature->isPlausible(999.0), Variable::Precipitation->isPlausible(-0.1), Variable::PrecipitationSum->isPlausible(0.0)]);
    },

    'Scheduler: waits for the time, runs once a day, catches up next day' => static function () use ($scheduler, $at): void {
        $calls = [];
        $s = $scheduler($calls, []);
        foreach (['2026-11-19 05:59', '2026-11-19 06:00', '2026-11-19 09:00', '2026-11-20 14:00'] as $time) {
            $s->tick($at($time));
        }
        check(['2026-11-19', '2026-11-20'], $calls);
    },

    'Scheduler: failed or crashed runs are retried after the interval' => static function () use ($scheduler, $at): void {
        $calls = [];
        $s = $scheduler($calls, [false, new RuntimeException('db down'), true]);
        foreach (['06:00', '06:10', '06:30', '07:00', '07:30'] as $time) {
            $s->tick($at("2026-11-19 {$time}"));
        }
        check(3, count($calls));
    },
];
