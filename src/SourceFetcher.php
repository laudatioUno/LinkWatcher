<?php

declare(strict_types=1);

namespace LinkWatcher;

final class SourceFetcher
{
    public function __construct(private array $checkerConfig)
    {
    }

    /** @return array{ok:bool,body:string,error:?string} */
    public function fetch(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => (int) $this->checkerConfig['connect_timeout'],
            CURLOPT_TIMEOUT => max(30, (int) $this->checkerConfig['timeout']),
            CURLOPT_USERAGENT => (string) $this->checkerConfig['user_agent'],
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'body' => '', 'error' => 'Abruf fehlgeschlagen: ' . $error];
        }
        if ($code !== 200) {
            return ['ok' => false, 'body' => '', 'error' => "Abruf fehlgeschlagen: HTTP {$code}"];
        }
        return ['ok' => true, 'body' => (string) $body, 'error' => null];
    }
}
