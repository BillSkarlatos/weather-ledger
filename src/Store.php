<?php

declare(strict_types=1);

namespace App;

use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/** Write side of the ledger: schema, locations and fetch runs. */
final readonly class Store
{
    public function __construct(private PDO $db)
    {
    }

    /** Applies migrations/*.sql in name order, each once, in a transaction. */
    public function migrate(string $directory): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version text PRIMARY KEY)');
        $applied = $this->db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        foreach (glob("{$directory}/*.sql") as $file) {
            if (!in_array(basename($file), $applied, true)) {
                $this->db->beginTransaction();
                $this->db->exec((string) file_get_contents($file));
                $this->db->prepare('INSERT INTO schema_migrations VALUES (?)')->execute([basename($file)]);
                $this->db->commit();
            }
        }
    }

    public function ensureRole(string $role, string $password): void
    {
        $exists = $this->db->prepare('SELECT 1 FROM pg_roles WHERE rolname = ?');
        $exists->execute([$role]);
        if (!$exists->fetchColumn()) {
            $this->db->exec("CREATE ROLE {$role} LOGIN PASSWORD {$this->db->quote($password)}");
        }
    }

    public function addLocation(string $name, float $latitude, float $longitude, string $timezone): int
    {
        $insert = $this->db->prepare('INSERT INTO locations (name, latitude, longitude, timezone) VALUES (?, ?, ?, ?) RETURNING id');
        $insert->execute([$name, $latitude, $longitude, (new DateTimeZone($timezone))->getName()]);

        return (int) $insert->fetchColumn();
    }

    /** @return list<Location> */
    public function activeLocations(): array
    {
        $rows = $this->db->query('SELECT id, name, latitude, longitude, timezone FROM locations WHERE active ORDER BY id')->fetchAll();

        return array_map(static fn(array $r) => new Location($r['id'], $r['name'], (float) $r['latitude'], (float) $r['longitude'], $r['timezone']), $rows);
    }

    /** Frees days locked by runs whose process died. */
    public function abandonStaleRuns(): void
    {
        $this->db->exec("UPDATE fetch_runs SET status = 'failed', finished_at = now(), error = 'Abandoned mid-run'
                         WHERE status = 'running' AND started_at < now() - interval '30 minutes'");
    }

    public function hasSucceeded(string $provider, int $locationId, string $runDate): bool
    {
        return (bool) $this->value(
            "SELECT 1 FROM fetch_runs WHERE provider_id = (SELECT id FROM providers WHERE code = ?)
             AND location_id = ? AND run_date = ? AND status = 'succeeded'",
            [$provider, $locationId, $runDate],
        );
    }

    /** @return int|null run id, or null when the same run is in progress elsewhere */
    public function startRun(string $provider, int $locationId, string $runDate): ?int
    {
        try {
            return (int) $this->value(
                'INSERT INTO fetch_runs (provider_id, location_id, run_date) SELECT id, ?, ? FROM providers WHERE code = ? RETURNING id',
                [$locationId, $runDate, $provider],
            );
        } catch (PDOException $e) {
            return $e->getCode() === '23505' ? null : throw $e; // unique violation: already running
        }
    }

    public function savePayload(int $runId, string $url, string $body): void
    {
        $this->db->prepare('INSERT INTO raw_payloads VALUES (?, ?, ?, ?)')->execute([$runId, $url, $body, hash('sha256', $body)]);
    }

    /** @param list<ForecastPoint> $points */
    public function succeedRun(int $runId, array $points, int $rejected): void
    {
        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT INTO forecast_values SELECT ?, id, ?, ?, ? FROM variables WHERE code = ?'
            );
            foreach ($points as $p) {
                $insert->execute([$runId, $p->validAt->format(DATE_ATOM), $p->localDate, $p->value, $p->variable->value]);
            }
            $this->finishRun($runId, 'succeeded', count($points), $rejected, null);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function failRun(int $runId, string $error): void
    {
        $this->finishRun($runId, 'failed', null, null, mb_substr($error, 0, 1000));
    }

    private function finishRun(int $runId, string $status, ?int $stored, ?int $rejected, ?string $error): void
    {
        $this->db->prepare(
            'UPDATE fetch_runs SET status = ?, finished_at = now(), values_stored = ?, values_rejected = ?, error = ? WHERE id = ?'
        )->execute([$status, $stored, $rejected, $error, $runId]);
    }

    private function value(string $sql, array $params): mixed
    {
        $query = $this->db->prepare($sql);
        $query->execute($params);

        return $query->fetchColumn();
    }
}
