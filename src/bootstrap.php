<?php

declare(strict_types=1);

spl_autoload_register(static fn(string $class) => str_starts_with($class, 'App\\')
    && require __DIR__ . '/' . substr($class, 4) . '.php');
