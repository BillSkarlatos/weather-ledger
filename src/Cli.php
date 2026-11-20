<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Cli
{
    private const USAGE = <<<'TXT'
        Usage: bin/console <command>
          setup                                  Create roles, migrate, add DEFAULT_LOCATION_* if none exists
          location:add <name> <lat> <lon> <tz>   e.g. "North field" 40.64 22.94 Europe/Athens
          fetch [--date=YYYY-MM-DD] [--force]    Fetch and store forecasts now (default: today)
          scheduler                              Run the daily scheduler

        TXT;

    public function __construct(private App $app)
    {
    }

    public function run(array $args): int
    {
        $command = array_shift($args);
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--(\w+)(?:=(.*))?$/', $arg, $match)) {
                $options[$match[1]] = $match[2] ?? true;
            }
        }

        return match ($command) {
            'setup' => $this->setup(),
            'location:add' => $this->addLocation(...$args),
            'fetch' => $this->fetch($options['date'] ?? $this->app->today(), isset($options['force'])),
            'scheduler' => $this->app->runScheduler(),
            default => $this->usage(),
        };
    }

    private function usage(): int
    {
        echo self::USAGE;

        return 1;
    }

    private function setup(): int
    {
        $store = $this->app->store();
        foreach (['weather_ingest' => 'DB_INGEST_PASSWORD', 'weather_reader' => 'DB_READER_PASSWORD'] as $role => $password) {
            if (App::env($password, '') !== '') {
                $store->ensureRole($role, App::env($password));
            }
        }
        $store->migrate(dirname(__DIR__) . '/migrations');
        if ($store->activeLocations() === [] && App::env('DEFAULT_LOCATION_NAME', '') !== '') {
            $this->addLocation(...array_map(
                static fn(string $field) => App::env("DEFAULT_LOCATION_{$field}"),
                ['NAME', 'LATITUDE', 'LONGITUDE', 'TIMEZONE'],
            ));
        }
        echo "Setup complete\n";

        return 0;
    }

    private function addLocation(string $name, string $latitude, string $longitude, string $timezone): int
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            throw new InvalidArgumentException('Latitude and longitude must be numbers');
        }
        $id = $this->app->store()->addLocation($name, (float) $latitude, (float) $longitude, $timezone);
        echo "Location #{$id} added\n";

        return 0;
    }

    private function fetch(string $date, bool $force): int
    {
        if (DateTimeImmutable::createFromFormat('!Y-m-d', $date)?->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('--date must be YYYY-MM-DD');
        }

        return $this->app->ingestor()->run($date, $force) ? 0 : 1;
    }
}
