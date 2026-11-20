<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

final class Http
{
    /** HTTPS GET with timeouts; network errors, 429 and 5xx are retried with back-off (Retry-After honoured). */
    public static function get(string $url, int $attempts = 3): string
    {
        for ($attempt = 1; ; $attempt++) {
            $retryAfter = null;
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$retryAfter): int {
                    if (preg_match('/^Retry-After:\s*(\d+)/i', $header, $match)) {
                        $retryAfter = (int) $match[1];
                    }
                    return strlen($header);
                },
            ]);
            $body = curl_exec($curl);
            $status = $body === false ? 0 : curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = $body === false ? curl_error($curl) : "HTTP {$status}: " . mb_strimwidth($body, 0, 300, '…');

            if ($status >= 200 && $status < 300) {
                return $body;
            }
            if (($status !== 0 && $status !== 429 && $status < 500) || $attempt >= $attempts) {
                throw new RuntimeException($error);
            }
            sleep(min($retryAfter ?? 2 ** $attempt, 30));
        }
    }
}
