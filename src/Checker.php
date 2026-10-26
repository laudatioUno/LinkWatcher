<?php

declare(strict_types=1);

namespace LinkWatcher;

/**
 * Checks URLs in parallel (curl_multi) with a global and a per-host concurrency limit.
 * HEAD first, GET as fallback (many servers reject HEAD), then a delayed retry for failures.
 */
final class Checker
{
    private const BODY_LIMIT = 131072; // we only need the status, not the full page

    /** @var callable(string):void */
    private $log;

    public function __construct(private array $cfg, ?callable $log = null)
    {
        $this->log = $log ?? static function (string $m): void {
        };
    }

    /**
     * @param array<int,string> $urls id => url
     * @return array<int,array{status:string,message:string,http_code:int,final_url:string,redirect_count:int,response_ms:int,method:string,attempts:int}>
     */
    public function checkAll(array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $useHead = (bool) $this->cfg['use_head'];
        $raw = $this->runJobs($urls, $useHead ? 'HEAD' : 'GET');

        if ($useHead) {
            $fallback = [];
            foreach ($raw as $id => $r) {
                if ($r['errno'] !== 0 || $r['http_code'] === 0 || $r['http_code'] >= 400) {
                    $fallback[$id] = $urls[$id];
                }
            }
            if ($fallback !== []) {
                ($this->log)(count($fallback) . ' URLs per GET nachgeprüft (HEAD nicht eindeutig)');
                $raw = array_replace($raw, $this->runJobs($fallback, 'GET'));
            }
        }

        $results = [];
        foreach ($raw as $id => $r) {
            $results[$id] = $this->classify($r, $urls[$id]) + ['attempts' => 1];
        }

        $retries = max(0, (int) $this->cfg['retry_attempts']);
        for ($attempt = 2; $attempt <= $retries + 1; $attempt++) {
            $failed = [];
            foreach ($results as $id => $r) {
                if ($r['status'] === 'error') {
                    $failed[$id] = $urls[$id];
                }
            }
            if ($failed === []) {
                break;
            }
            $delay = max(0, (int) $this->cfg['retry_delay']);
            ($this->log)(count($failed) . " fehlerhafte URLs – neuer Versuch in {$delay}s");
            if ($delay > 0) {
                sleep($delay);
            }
            foreach ($this->runJobs($failed, 'GET') as $id => $r) {
                $results[$id] = $this->classify($r, $urls[$id]) + ['attempts' => $attempt];
            }
        }

        return $results;
    }

    /** @return array<int,array{errno:int,error:string,http_code:int,final_url:string,redirect_count:int,response_ms:int,method:string}> */
    private function runJobs(array $urls, string $method): array
    {
        $maxTotal = max(1, (int) $this->cfg['max_concurrency']);
        $maxHost = max(1, (int) $this->cfg['max_per_host']);

        $queue = $urls;
        $active = [];
        $hostCount = [];
        $results = [];
        $mh = curl_multi_init();

        do {
            // Fill free slots, respecting the per-host limit.
            foreach ($queue as $id => $url) {
                if (count($active) >= $maxTotal) {
                    break;
                }
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if (($hostCount[$host] ?? 0) >= $maxHost) {
                    continue;
                }
                $ch = $this->createHandle($url, $method);
                curl_multi_add_handle($mh, $ch);
                $active[spl_object_id($ch)] = ['id' => $id, 'host' => $host, 'ch' => $ch];
                $hostCount[$host] = ($hostCount[$host] ?? 0) + 1;
                unset($queue[$id]);
            }

            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $job = $active[spl_object_id($ch)];
                $errno = (int) $info['result'];
                $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                // We abort large bodies on purpose (write error) – that is not a failure.
                if ($errno === CURLE_WRITE_ERROR && $httpCode > 0) {
                    $errno = 0;
                }
                $results[$job['id']] = [
                    'errno' => $errno,
                    'error' => $errno !== 0 ? (curl_error($ch) ?: curl_strerror($errno)) : '',
                    'http_code' => $httpCode,
                    'final_url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
                    'redirect_count' => (int) curl_getinfo($ch, CURLINFO_REDIRECT_COUNT),
                    'response_ms' => (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
                    'method' => $method,
                ];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                $hostCount[$job['host']]--;
                unset($active[spl_object_id($ch)]);
            }

            if ($active !== [] && curl_multi_select($mh, 1.0) === -1) {
                usleep(100000);
            }
        } while ($active !== [] || $queue !== []);

        curl_multi_close($mh);
        return $results;
    }

    private function createHandle(string $url, string $method): \CurlHandle
    {
        $ch = curl_init($url);
        $received = 0;
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => (int) $this->cfg['max_redirects'],
            CURLOPT_CONNECTTIMEOUT => (int) $this->cfg['connect_timeout'],
            CURLOPT_TIMEOUT => (int) $this->cfg['timeout'],
            CURLOPT_USERAGENT => (string) $this->cfg['user_agent'],
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: de-CH,de;q=0.9,fr;q=0.8,it;q=0.7,en;q=0.6',
            ],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => (bool) $this->cfg['verify_tls'],
            CURLOPT_SSL_VERIFYHOST => $this->cfg['verify_tls'] ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_COOKIEFILE => '', // keep cookies across redirects (some sites need it)
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$received): int {
                $received += strlen($data);
                return $received > self::BODY_LIMIT ? 0 : strlen($data);
            },
        ]);
        return $ch;
    }

    /** Turns a raw curl result into ok / warning / error with a readable German message. */
    public function classify(array $r, string $url): array
    {
        $base = [
            'http_code' => $r['http_code'],
            'final_url' => $r['final_url'],
            'redirect_count' => $r['redirect_count'],
            'response_ms' => $r['response_ms'],
            'method' => $r['method'],
        ];

        if ($r['errno'] !== 0) {
            return $base + ['status' => 'error', 'message' => self::curlMessage($r['errno'], $r['error'])];
        }

        $code = $r['http_code'];
        if ($code === 0 || $code >= 400) {
            $warningCodes = array_map('intval', (array) $this->cfg['warning_codes']);
            $text = "HTTP {$code}" . (self::reason($code) ? ' ' . self::reason($code) : '');
            if (in_array($code, $warningCodes, true)) {
                return $base + ['status' => 'warning', 'message' => $text . ' – evtl. Bot-Schutz, bitte manuell prüfen'];
            }
            return $base + ['status' => 'error', 'message' => $text];
        }

        if ($this->cfg['redirect_is_warning'] && $r['redirect_count'] > 0
            && self::normalize($r['final_url']) !== self::normalize($url)) {
            return $base + ['status' => 'warning', 'message' => 'Weiterleitung auf ' . $r['final_url']];
        }

        $slow = (int) $this->cfg['slow_threshold_ms'];
        if ($slow > 0 && $r['response_ms'] > $slow) {
            return $base + ['status' => 'warning', 'message' => sprintf('Langsam (%.1f s)', $r['response_ms'] / 1000)];
        }

        return $base + ['status' => 'ok', 'message' => "HTTP {$code}" . (self::reason($code) ? ' ' . self::reason($code) : '')];
    }

    /** Normalises a URL so that trivial differences (case, trailing slash, default port) are not reported as redirects. */
    public static function normalize(string $url): string
    {
        $p = parse_url($url);
        if (!$p || !isset($p['host'])) {
            return $url;
        }
        $scheme = strtolower($p['scheme'] ?? 'http');
        $port = isset($p['port']) && !(($scheme === 'http' && $p['port'] === 80) || ($scheme === 'https' && $p['port'] === 443))
            ? ':' . $p['port'] : '';
        $path = rtrim($p['path'] ?? '', '/');
        $query = isset($p['query']) ? '?' . $p['query'] : '';
        return $scheme . '://' . strtolower($p['host']) . $port . $path . $query;
    }

    private static function curlMessage(int $errno, string $error): string
    {
        return match ($errno) {
            CURLE_COULDNT_RESOLVE_HOST => 'Domain nicht auflösbar (DNS)',
            CURLE_COULDNT_CONNECT => 'Verbindung fehlgeschlagen',
            CURLE_OPERATION_TIMEDOUT => 'Zeitüberschreitung',
            CURLE_TOO_MANY_REDIRECTS => 'Zu viele Weiterleitungen',
            35, 51, 58, 60, 77, 83 => 'TLS-Fehler: ' . $error, // SSL connect / peer verification / cert problems
            default => 'Fehler: ' . $error,
        };
    }

    private static function reason(int $code): string
    {
        return [
            200 => 'OK', 204 => 'No Content', 400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
            404 => 'Not Found', 405 => 'Method Not Allowed', 410 => 'Gone', 429 => 'Too Many Requests',
            500 => 'Internal Server Error', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
        ][$code] ?? '';
    }
}
