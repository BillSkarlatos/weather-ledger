<?php

declare(strict_types=1);

use App\Api;
use App\App;
use App\Ingestor;
use App\OpenMeteo;
use App\Queries;
use App\Store;
use App\WeatherApi;

if (getenv('TEST_INTEGRATION') !== '1') {
    return [];
}

// Isolated throw-away schema: safe to run against any database.
$db = (new App())->db();
$schema = 'test_' . bin2hex(random_bytes(4));
$db->exec("CREATE SCHEMA {$schema}; SET search_path TO {$schema}");
register_shutdown_function(static fn() => $db->exec("DROP SCHEMA {$schema} CASCADE"));
$store = new Store($db);
$store->migrate(__DIR__ . '/../migrations');
$location = (string) $store->addLocation('Berlin', 52.52, 13.41, 'Europe/Berlin');

$requests = [];
$run = static function (string $date, array $overrides = []) use ($store, &$requests): bool {
    $responses = $overrides + ['api.open-meteo.com' => fixture('open_meteo'), 'api.weatherapi.com' => fixture('weatherapi')];
    $fetch = static function (string $url) use ($responses, &$requests): string {
        $requests[] = $url;
        $response = $responses[parse_url($url, PHP_URL_HOST)];
        return $response instanceof Throwable ? throw $response : $response;
    };
    return (new Ingestor([new OpenMeteo('https://api.open-meteo.com'), new WeatherApi('SECRET', 3)], $store, $fetch(...), 3))->run($date);
};
$rows = static fn(string $sql): array => $db->query($sql)->fetchAll(PDO::FETCH_NUM);
$api = static fn(string $path, array $params = [], string $token = 'Bearer t') => (new Api(new Queries($db), ['t']))->handle('GET', $path, $params, $token);

return [
    'Stores both providers, drops implausible values, redacts keys' => static function () use ($run, $rows): void {
        check(true, $run('2026-11-19'));
        check([['succeeded', 9, 0], ['succeeded', 7, 1]], $rows('SELECT status, values_stored, values_rejected FROM fetch_runs ORDER BY provider_id'));
        check([], $rows("SELECT 1 FROM raw_payloads WHERE request_url LIKE '%SECRET%'"), 'key redacted');
    },

    'Re-running a stored day calls no provider' => static function () use ($run, &$requests): void {
        $requests = [];
        check([true, []], [$run('2026-11-19'), $requests]);
    },

    'A failing provider does not block the other and is retried alone' => static function () use ($run, $rows, &$requests): void {
        check(false, $run('2026-11-20', ['api.weatherapi.com' => new RuntimeException('HTTP 403: quota exceeded')]));
        check([['succeeded'], ['failed']], $rows("SELECT status FROM fetch_runs WHERE run_date = '2026-11-20' ORDER BY provider_id"));
        $requests = [];
        check([true, 1], [$run('2026-11-20'), count($requests)]);
    },

    'A malformed response fails the run but keeps the payload' => static function () use ($run, $rows): void {
        check(false, $run('2026-11-21', ['api.open-meteo.com' => '{"hourly":{}}']));
        check([['failed', "Missing field 'utc_offset_seconds'"]], $rows(
            "SELECT status, error FROM fetch_runs r JOIN raw_payloads p ON p.fetch_run_id = r.id WHERE run_date = '2026-11-21' AND provider_id = 1"
        ));
    },

    'Values, payloads and finished runs are immutable' => static function () use ($db): void {
        foreach (['UPDATE forecast_values SET value = 0', 'DELETE FROM raw_payloads', "UPDATE fetch_runs SET status = 'running'"] as $sql) {
            check(true, fails(static fn() => $db->exec($sql)), $sql);
        }
    },

    'API: auth, method and routing errors' => static function () use ($api): void {
        check([401, 401, 404, 200], [$api('/api/v1/runs', [], '')[0], $api('/api/v1/runs', [], 'Bearer x')[0], $api('/api/v1/handle')[0], $api('/health')[0]]);
    },

    'API: forecasts (failed runs ignored), compare and revisions' => static function () use ($api, $location): void {
        $forecasts = $api('/api/v1/forecasts', ['location_id' => $location, 'variable' => 'temperature_2m_max'])[1]['data'];
        check([['open_meteo', '2026-11-20'], ['weatherapi', '2026-11-21']], array_map(static fn($s) => [$s['provider'], $s['issued_on']], $forecasts));
        $compare = $api('/api/v1/compare', ['location_id' => $location, 'variable' => 'temperature_2m_max', 'issued_on' => '2026-11-19'])[1]['data'];
        check([['open_meteo' => 7.0, 'weatherapi' => 7.5], 0.5], [$compare[0]['values'], $compare[0]['spread']]);
        $revisions = $api('/api/v1/revisions', ['location_id' => $location, 'variable' => 'precipitation_sum', 'date' => '2026-11-19', 'provider' => 'weatherapi'])[1]['data'];
        check(['2026-11-19', '2026-11-20', '2026-11-21'], array_column($revisions, 'issued_on'));
    },

    'API: invalid parameters return 400' => static function () use ($api): void {
        check([400, 400, 400], [
            $api('/api/v1/forecasts', ['location_id' => 'abc', 'variable' => 'temperature_2m'])[0],
            $api('/api/v1/forecasts', ['location_id' => '1', 'variable' => 'humidity'])[0],
            $api('/api/v1/revisions', ['location_id' => '1', 'variable' => 'precipitation'])[0],
        ]);
    },
];
