<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class HttpClient
{
    private static array $lastHeaders = [];

    /**
     * GET a URL with retry logic, rotating user-agents, and optional proxy.
     */
    public static function get(string $url, array $config = []): ?string
    {
        $maxRetries = $config['max_retries'] ?? 3;
        $timeout    = $config['timeout']     ?? 30;
        $proxy      = $config['proxy']       ?? null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => self::randomUserAgent(),
                CURLOPT_HTTPHEADER     => self::buildHeaders($url, $config),
                CURLOPT_ENCODING       => '',  // accept gzip
                CURLOPT_COOKIEFILE     => '',  // enable cookie jar
                CURLOPT_COOKIEJAR      => '',
                CURLOPT_HEADER         => false,
            ]);

            if ($proxy) {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
            }

            // Capture response headers
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) {
                self::$lastHeaders[] = trim($header);
                return strlen($header);
            });
            self::$lastHeaders = [];

            $body     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($error) {
                Log::warning("[HttpClient] cURL error on {$url}: {$error} (attempt {$attempt})");
                sleep($attempt);
                continue;
            }

            if ($httpCode === 200 && $body) {
                return $body;
            }

            if ($httpCode === 429) {
                // Rate limited — back off
                $wait = pow(2, $attempt) * 2;
                Log::warning("[HttpClient] Rate limited (429) on {$url}. Waiting {$wait}s...");
                sleep($wait);
                continue;
            }

            if ($httpCode === 404) {
                Log::info("[HttpClient] 404 on {$url}");
                return null;
            }

            if (in_array($httpCode, [403, 401, 503])) {
                Log::warning("[HttpClient] HTTP {$httpCode} on {$url} (attempt {$attempt})");
                sleep($attempt * 2);
                continue;
            }

            return $body ?: null;
        }

        Log::error("[HttpClient] Failed to fetch after {$maxRetries} attempts: {$url}");
        return null;
    }

    public static function lastHeaders(): array
    {
        return self::$lastHeaders;
    }

    private static function buildHeaders(string $url, array $config): array
    {
        $host    = parse_url($url, PHP_URL_HOST);
        $referer = parse_url($url, PHP_URL_SCHEME) . '://' . $host;

        return [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            "Referer: {$referer}",
            'Cache-Control: max-age=0',
        ];
    }

    private static function randomUserAgent(): string
    {
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        ];

        return $agents[array_rand($agents)];
    }
}