<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/** Composition root: configuration, services and logging. */
final class App
{
    private ?PDO $db = null;

    /** Reads a setting; NAME_FILE (Docker secrets) takes precedence over NAME. */
    public static function env(string $name, ?string $default = null): string
    {
        $file = getenv("{$name}_FILE");
        $value = $file ? trim((string) file_get_contents($file)) : getenv($name);

        return $value !== false && $value !== '' ? $value : $default ?? throw new RuntimeException("Missing setting {$name}");
    }

    /** JSON lines on stderr, collected by `docker compose logs`. */
    public static function log(string $level, string $message, array $context = []): void
    {
        $line = ['time' => date(DATE_ATOM), 'level' => $level, 'message' => $message] + $context;
        file_put_contents('php://stderr', json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    public function db(): PDO
    {
        return $this->db ??= new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', self::env('DB_HOST', 'db'), self::env('DB_PORT', '5432'), self::env('DB_NAME')),
            self::env('DB_USER'),
            self::env('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    public function store(): Store
    {
        return new Store($this->db());
    }

    public function ingestor(): Ingestor
    {
        return new Ingestor(
            [
                new OpenMeteo(self::env('OPENMETEO_BASE_URL', 'https://api.open-meteo.com'), self::env('OPENMETEO_API_KEY', '')),
                new WeatherApi(self::env('WEATHERAPI_KEY'), (int) self::env('WEATHERAPI_MAX_DAYS', '3')),
            ],
            $this->store(),
            Http::get(...),
            (int) self::env('FORECAST_DAYS', '3'),
        );
    }

    /** Long-running: ticks every minute; each run gets a fresh database connection. */
    public function runScheduler(): never
    {
        $zone = new DateTimeZone(self::env('SCHEDULE_TIMEZONE', 'UTC'));
        $scheduler = new Scheduler(
            static fn(string $date): bool => (new self())->ingestor()->run($date),
            self::env('SCHEDULE_TIME', '06:00'),
            (int) self::env('RETRY_MINUTES', '30'),
        );
        while (true) {
            $scheduler->tick(new DateTimeImmutable('now', $zone));
            touch(self::env('HEARTBEAT_FILE', '/tmp/scheduler.heartbeat'));
            sleep(60);
        }
    }

    public function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::env('SCHEDULE_TIMEZONE', 'UTC'))))->format('Y-m-d');
    }

    public function api(): Api
    {
        return new Api(new Queries($this->db()), array_filter(array_map(trim(...), explode(',', self::env('API_TOKENS', '')))));
    }
}
