<?php

declare(strict_types=1);

namespace App;

use UnexpectedValueException;

/** Adapter for one weather provider: builds the request URL and normalises the response. */
abstract class Provider
{
    /** Matches `providers.code`. */
    abstract public function code(): string;

    abstract public function url(Location $location, int $days): string;

    /**
     * @return list<ForecastPoint>
     * @throws UnexpectedValueException when the response lacks expected fields or units
     */
    abstract public function parse(array $payload): array;

    /** Reads a dotted path such as "daily.time". */
    protected static function field(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                throw new UnexpectedValueException("Missing field '{$path}'");
            }
            $data = $data[$key];
        }

        return $data;
    }

    protected static function list(array $data, string $path): array
    {
        $value = self::field($data, $path);

        return is_array($value) ? $value : throw new UnexpectedValueException("Field '{$path}' is not a list");
    }

    protected static function number(array $data, string $path): float
    {
        $value = self::field($data, $path);

        return is_int($value) || is_float($value) ? $value : throw new UnexpectedValueException("Field '{$path}' is not a number");
    }
}
