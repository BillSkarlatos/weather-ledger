<?php

declare(strict_types=1);

namespace App;

use Closure;
use Throwable;
use UnexpectedValueException;

/** Fetches every active location from every provider. Idempotent per day; one failure never stops the rest. */
final readonly class Ingestor
{
    /**
     * @param list<Provider> $providers
     * @param Closure(string $url): string $fetch
     */
    public function __construct(private array $providers, private Store $store, private Closure $fetch, private int $days)
    {
    }

    /** @return bool true when every provider/location pair is stored for the date */
    public function run(string $runDate, bool $force = false): bool
    {
        $this->store->abandonStaleRuns();
        $complete = true;
        foreach ($this->store->activeLocations() as $location) {
            foreach ($this->providers as $provider) {
                $complete = $this->ingest($provider, $location, $runDate, $force) && $complete;
            }
        }

        return $complete;
    }

    private function ingest(Provider $provider, Location $location, string $runDate, bool $force): bool
    {
        $context = ['provider' => $provider->code(), 'location' => $location->name, 'run_date' => $runDate];
        if (!$force && $this->store->hasSucceeded($provider->code(), $location->id, $runDate)) {
            App::log('info', 'Already stored, skipped (use --force to fetch again)', $context);
            return true;
        }
        $runId = $this->store->startRun($provider->code(), $location->id, $runDate);
        if ($runId === null) {
            App::log('warning', 'Run already in progress', $context);
            return false;
        }

        try {
            $url = $provider->url($location, $this->days);
            $body = ($this->fetch)($url);
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $this->store->savePayload($runId, self::redact($url), $body);

            $points = $provider->parse($payload);
            $plausible = array_values(array_filter($points, static fn(ForecastPoint $p) => $p->variable->isPlausible($p->value)));
            if ($plausible === []) {
                throw new UnexpectedValueException('No usable values');
            }
            $this->store->succeedRun($runId, $plausible, count($points) - count($plausible));
            App::log('info', 'Forecast stored', $context + ['values' => count($plausible)]);

            return true;
        } catch (Throwable $e) {
            $error = self::redact($e->getMessage());
            $this->store->failRun($runId, $error);
            App::log('error', 'Forecast fetch failed', $context + ['error' => $error]);

            return false;
        }
    }

    /** API keys travel in query strings; they must never reach the database or logs. */
    private static function redact(string $text): string
    {
        return (string) preg_replace('/\b((?:api)?key=)[^&\s"]+/i', '$1***', $text);
    }
}
