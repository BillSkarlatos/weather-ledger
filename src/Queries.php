<?php

declare(strict_types=1);

namespace App;

use PDO;

/** Read side of the ledger. Timestamps are UTC ISO 8601. */
final readonly class Queries
{
    public const TABLES = ['providers', 'variables', 'locations'];

    public function __construct(private PDO $db)
    {
    }

    public function table(string $table): array
    {
        return in_array($table, self::TABLES, true) ? $this->select("SELECT * FROM {$table} ORDER BY id") : [];
    }

    public function runs(?int $locationId, ?string $provider, ?string $status, int $limit): array
    {
        return $this->select(
            'SELECT r.id, p.code AS provider, r.location_id, r.run_date, r.status, utc_iso(r.started_at) AS started_at,
                    utc_iso(r.finished_at) AS finished_at, r.values_stored, r.values_rejected, r.error
             FROM fetch_runs r JOIN providers p ON p.id = r.provider_id
             WHERE r.location_id = COALESCE(:location, r.location_id) AND p.code = COALESCE(:provider, p.code)
               AND r.status = COALESCE(:status, r.status)
             ORDER BY r.id DESC LIMIT :limit',
            ['location' => $locationId, 'provider' => $provider, 'status' => $status, 'limit' => $limit],
        );
    }

    /** Values of the latest successful run per provider issued on or before $issuedOn. */
    public function forecast(int $locationId, string $variable, ?string $provider, string $issuedOn, string $from, string $to): array
    {
        return $this->select(
            "WITH latest AS (
                 SELECT DISTINCT ON (r.provider_id) r.id, r.run_date, r.started_at, p.code
                 FROM fetch_runs r JOIN providers p ON p.id = r.provider_id
                 WHERE r.location_id = :location AND r.status = 'succeeded' AND r.run_date <= :issued_on
                   AND p.code = COALESCE(:provider, p.code)
                 ORDER BY r.provider_id, r.run_date DESC, r.started_at DESC
             )
             SELECT l.code AS provider, l.run_date AS issued_on, utc_iso(l.started_at) AS issued_at,
                    utc_iso(v.valid_at) AS valid_at, v.local_date, v.value
             FROM latest l
             JOIN forecast_values v ON v.fetch_run_id = l.id
             JOIN variables var ON var.id = v.variable_id AND var.code = :variable
             WHERE v.valid_at >= :from AND v.valid_at < :to
             ORDER BY l.code, v.valid_at",
            ['location' => $locationId, 'variable' => $variable, 'provider' => $provider, 'issued_on' => $issuedOn, 'from' => $from, 'to' => $to],
        );
    }

    /** Every forecast made for one target instant (or local day when $byDate), oldest run first. */
    public function revisions(int $locationId, string $variable, ?string $provider, string $target, bool $byDate): array
    {
        $column = $byDate ? 'v.local_date' : 'v.valid_at';

        return $this->select(
            "SELECT p.code AS provider, r.run_date AS issued_on, utc_iso(v.valid_at) AS valid_at, v.local_date,
                    round(extract(epoch FROM v.valid_at - r.started_at) / 3600)::int AS lead_hours, v.value
             FROM forecast_values v
             JOIN variables var ON var.id = v.variable_id AND var.code = :variable
             JOIN fetch_runs r ON r.id = v.fetch_run_id AND r.location_id = :location AND r.status = 'succeeded'
             JOIN providers p ON p.id = r.provider_id AND p.code = COALESCE(:provider, p.code)
             WHERE {$column} = :target
             ORDER BY p.code, v.valid_at, r.started_at",
            ['location' => $locationId, 'variable' => $variable, 'provider' => $provider, 'target' => $target],
        );
    }

    private function select(string $sql, array $params = []): array
    {
        $query = $this->db->prepare($sql);
        foreach ($params as $name => $value) {
            $query->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $query->execute();

        $decimals = [];
        for ($i = 0; $i < $query->columnCount(); $i++) {
            $column = $query->getColumnMeta($i);
            if ($column['native_type'] === 'numeric') {
                $decimals[] = $column['name']; // pdo_pgsql returns numeric as strings
            }
        }

        return array_map(static function (array $row) use ($decimals): array {
            foreach ($decimals as $name) {
                $row[$name] = (float) $row[$name];
            }
            return $row;
        }, $query->fetchAll());
    }
}
