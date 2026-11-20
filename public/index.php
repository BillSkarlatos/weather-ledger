<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

try {
    [$status, $body] = (new App\App())->api()->handle(
        $_SERVER['REQUEST_METHOD'],
        (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
        $_GET,
        array_change_key_case(getallheaders())['authorization'] ?? '',
    );
} catch (Throwable) {
    [$status, $body] = [503, ['error' => 'Service unavailable']];
}

http_response_code($status);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
