<?php

declare(strict_types=1);

/** Minimal runner: each tests/*Test.php returns [name => callable]. DB tests need TEST_INTEGRATION=1. */

require __DIR__ . '/../src/bootstrap.php';

function check(mixed $expected, mixed $actual, string $label = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$label} expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function fails(callable $code): bool
{
    try {
        $code();
        return false;
    } catch (Throwable) {
        return true;
    }
}

function fixture(string $name): string
{
    return (string) file_get_contents(__DIR__ . "/fixtures/{$name}.json");
}

$failed = 0;
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    foreach (require $file as $name => $test) {
        try {
            $test();
            echo "  ok    {$name}\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  FAIL  {$name}: {$e->getMessage()}\n";
        }
    }
}
echo $failed ? "{$failed} failed\n" : "All passed\n";
exit($failed ? 1 : 0);
