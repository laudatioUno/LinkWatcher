<?php

declare(strict_types=1);

namespace LinkWatcher;

final class Config
{
    private function __construct(private array $data)
    {
    }

    public static function load(?string $path = null): self
    {
        $path ??= (getenv('LINKWATCHER_CONFIG') ?: LW_ROOT . '/config/config.php');
        if (!is_file($path)) {
            throw new \RuntimeException(
                "Konfigurationsdatei nicht gefunden: {$path}. Kopiere config/config.example.php nach config/config.php und passe sie an."
            );
        }
        $data = require $path;
        if (!is_array($data)) {
            throw new \RuntimeException("Die Konfigurationsdatei {$path} muss ein Array zurückgeben.");
        }

        $config = new self(self::merge(self::defaults(), $data));

        // Secrets may also come from the environment so they never have to live in a file.
        $envPassword = getenv('LINKWATCHER_SMTP_PASSWORD');
        if ($envPassword !== false && $envPassword !== '') {
            $config->data['mail']['smtp']['password'] = $envPassword;
        }

        date_default_timezone_set($config->get('app.timezone', 'Europe/Zurich'));

        return $config;
    }

    public static function fromArray(array $data): self
    {
        return new self(self::merge(self::defaults(), $data));
    }

    public static function defaults(): array
    {
        return [
            'app' => [
                'name' => 'LinkWatcher',
                'base_url' => '',
                'timezone' => 'Europe/Zurich',
            ],
            'db_path' => LW_ROOT . '/data/linkwatcher.sqlite',
            'lock_file' => LW_ROOT . '/data/linkwatcher.lock',
            'sources' => [],
            'key_filter' => null,
            'checker' => [
                'use_head' => true,
                'connect_timeout' => 10,
                'timeout' => 20,
                'max_concurrency' => 8,
                'max_per_host' => 2,
                'max_redirects' => 10,
                'retry_attempts' => 1,
                'retry_delay' => 15,
                'slow_threshold_ms' => 8000,
                'redirect_is_warning' => true,
                'warning_codes' => [401, 403, 429],
                'verify_tls' => true,
                'user_agent' => 'Mozilla/5.0 (compatible; LinkWatcher/' . LW_VERSION . '; +https://github.com/laudatioUno/LinkWatcher)',
            ],
            'alerts' => [
                'enabled' => true,
                'on_warnings' => false,
                'only_new_problems' => false,
                'stale_after_hours' => 26,
                'heartbeat_url' => '',
            ],
            'mail' => [
                'transport' => 'smtp',
                'from' => '',
                'from_name' => 'LinkWatcher',
                'to' => [],
                'subject_prefix' => '[LinkWatcher]',
                'smtp' => [
                    'host' => '',
                    'port' => 587,
                    'encryption' => 'tls',
                    'username' => '',
                    'password' => '',
                    'timeout' => 20,
                    'verify_tls' => true,
                ],
            ],
            'admin' => [
                'username' => 'admin',
                'password_hash' => '',
            ],
            'history_days' => 90,
        ];
    }

    /** Recursive merge for associative arrays; lists are replaced as a whole. */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])
                && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function all(): array
    {
        return $this->data;
    }

    /**
     * Expands the configured source groups into one definition per file.
     *
     * @return list<array{source_key:string,label:string,language:string,format:string,fetch_url:string,view_url:?string}>
     */
    public function sourceFiles(): array
    {
        $out = [];
        foreach ($this->get('sources', []) as $i => $group) {
            $label = (string) ($group['label'] ?? 'Quelle ' . ($i + 1));
            $format = (string) ($group['format'] ?? 'auto');
            $repo = trim((string) ($group['repo'] ?? ''), '/');
            $branch = (string) ($group['branch'] ?? 'main');

            foreach ($group['files'] ?? [] as $language => $file) {
                $fetchUrl = null;
                $viewUrl = null;
                if (is_array($file)) {
                    $fetchUrl = $file['url'] ?? null;
                    $viewUrl = $file['view_url'] ?? null;
                    $file = $file['path'] ?? null;
                }
                if ($fetchUrl === null && $file !== null && $repo !== '') {
                    $encodedPath = implode('/', array_map('rawurlencode', explode('/', ltrim((string) $file, '/'))));
                    $fetchUrl = "https://raw.githubusercontent.com/{$repo}/" . rawurlencode($branch) . "/{$encodedPath}";
                    $viewUrl ??= "https://github.com/{$repo}/blob/" . rawurlencode($branch) . "/{$encodedPath}";
                }
                if ($fetchUrl === null) {
                    throw new \RuntimeException("Quelle '{$label}' / Sprache '{$language}': weder 'repo'+Pfad noch 'url' angegeben.");
                }
                $fileFormat = $format === 'auto' ? self::guessFormat((string) $fetchUrl) : $format;
                $out[] = [
                    'source_key' => $label . ':' . $language,
                    'label' => $label,
                    'language' => (string) $language,
                    'format' => $fileFormat,
                    'fetch_url' => (string) $fetchUrl,
                    'view_url' => $viewUrl,
                ];
            }
        }
        return $out;
    }

    /** @return list<string> languages in configured order */
    public function languages(): array
    {
        $langs = [];
        foreach ($this->sourceFiles() as $file) {
            $langs[$file['language']] = true;
        }
        return array_keys($langs);
    }

    private static function guessFormat(string $url): string
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        return str_ends_with($path, '.xml') ? 'android_xml' : 'ios_strings';
    }
}
