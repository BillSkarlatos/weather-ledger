<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/** Read-only JSON API. Every route except /health needs a bearer token. */
final class Api
{
    private const ROUTES = ['runs', 'forecasts', 'compare', 'revisions'];

    private array $params = [];

    /** @param list<string> $tokens accepted bearer tokens; none means every request is refused */
    public function __construct(private readonly Queries $queries, private readonly array $tokens)
    {
    }

    /** @return array{int, array} HTTP status and JSON body */
    public function handle(string $method, string $path, array $params, string $authorization): array
    {
        $this->params = $params;
        $route = substr(rtrim($path, '/'), strlen('/api/v1/'));
        try {
            return match (true) {
                $method !== 'GET' => [405, ['error' => 'Method not allowed']],
                $path === '/health' => [200, ['status' => $this->queries->table('providers') ? 'ok' : 'empty']],
                !str_starts_with($path, '/api/v1/') || !in_array($route, [...self::ROUTES, ...Queries::TABLES], true)
                    => [404, ['error' => 'Not found']],
                !$this->isAuthorized($authorization) => [401, ['error' => 'Missing or invalid bearer token']],
                default => [200, ['data' => in_array($route, self::ROUTES, true) ? $this->$route() : $this->queries->table($route)]],
            };
        } catch (InvalidArgumentException $e) {
            return [400, ['error' => $e->getMessage()]];
        } catch (Throwable $e) {
            App::log('error', 'API request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return [500, ['error' => 'Internal server error']];
        }
    }

    private function runs(): array
    {
        return $this->queries->runs($this->id('location_id', false), $this->code('provider'), $this->code('status'), min($this->id('limit', false) ?? 50, 500));
    }

    /** Latest forecast per provider, optionally as issued on a past date. */
    private function forecasts(): array
    {
        $series = [];
        foreach ($this->queries->forecast(
            $this->id('location_id'), $this->variable(), $this->code('provider'),
            $this->date('issued_on') ?? 'infinity', $this->timestamp('from') ?? '-infinity', $this->timestamp('to') ?? 'infinity',
        ) as $row) {
            $series[$row['provider']] ??= ['provider' => $row['provider'], 'issued_on' => $row['issued_on'], 'issued_at' => $row['issued_at'], 'values' => []];
            $series[$row['provider']]['values'][] = ['valid_at' => $row['valid_at'], 'local_date' => $row['local_date'], 'value' => $row['value']];
        }

        return array_values($series);
    }

    /** Providers side by side per target time, with their spread (max − min). */
    private function compare(): array
    {
        $rows = [];
        foreach ($this->forecasts() as $series) {
            foreach ($series['values'] as $v) {
                $rows[$v['valid_at']] ??= ['valid_at' => $v['valid_at'], 'local_date' => $v['local_date'], 'values' => []];
                $rows[$v['valid_at']]['values'][$series['provider']] = $v['value'];
            }
        }
        ksort($rows);

        return array_values(array_map(static fn(array $r) => $r + ['spread' => count($r['values']) > 1 ? round(max($r['values']) - min($r['values']), 2) : null], $rows));
    }

    /** How the forecast for one `valid_at` or local `date` changed run after run. */
    private function revisions(): array
    {
        $date = $this->date('date');
        $target = $date ?? $this->timestamp('valid_at') ?? throw new InvalidArgumentException("Parameter 'valid_at' or 'date' is required");

        return $this->queries->revisions($this->id('location_id'), $this->variable(), $this->code('provider'), $target, $date !== null);
    }

    private function isAuthorized(string $authorization): bool
    {
        $token = preg_match('/^Bearer\s+(\S+)$/', $authorization, $match) ? $match[1] : '';

        return $token !== '' && array_filter($this->tokens, static fn(string $t) => hash_equals($t, $token)) !== [];
    }

    private function param(string $name): ?string
    {
        $value = $this->params[$name] ?? '';

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function id(string $name, bool $required = true): ?int
    {
        $value = $this->param($name);
        if ($value === null && !$required) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            ?: throw new InvalidArgumentException("Parameter '{$name}' must be a positive integer");
    }

    private function variable(): string
    {
        return Variable::tryFrom($this->param('variable') ?? '')?->value
            ?? throw new InvalidArgumentException("Parameter 'variable' must be one of: " . implode(', ', array_column(Variable::cases(), 'value')));
    }

    private function code(string $name): ?string
    {
        $value = $this->param($name);

        return $value === null || preg_match('/^[a-z_]{1,32}$/', $value) ? $value : throw new InvalidArgumentException("Parameter '{$name}' is invalid");
    }

    private function date(string $name): ?string
    {
        $value = $this->param($name);

        return $value === null || DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value
            ? $value : throw new InvalidArgumentException("Parameter '{$name}' must be YYYY-MM-DD");
    }

    private function timestamp(string $name): ?string
    {
        $value = $this->param($name);
        try {
            return $value === null ? null : (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        } catch (Throwable) {
            throw new InvalidArgumentException("Parameter '{$name}' must be an ISO 8601 timestamp");
        }
    }
}
