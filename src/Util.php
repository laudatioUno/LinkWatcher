<?php

declare(strict_types=1);

namespace LinkWatcher;

final class Util
{
    /** Current time in UTC, as stored in the database. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** Formats a stored UTC timestamp in the configured local timezone. */
    public static function fmt(?string $utc, string $format = 'd.m.Y H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '–';
        }
        $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($format);
    }

    /** Human readable relative time ("vor 3 Std."). */
    public static function ago(?string $utc): string
    {
        if ($utc === null || $utc === '') {
            return 'nie';
        }
        $diff = time() - (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp();
        if ($diff < 60) {
            return 'gerade eben';
        }
        if ($diff < 3600) {
            return 'vor ' . intdiv($diff, 60) . ' Min.';
        }
        if ($diff < 86400) {
            return 'vor ' . intdiv($diff, 3600) . ' Std.';
        }
        $days = intdiv($diff, 86400);
        return 'vor ' . $days . ($days === 1 ? ' Tag' : ' Tagen');
    }

    public static function hoursSince(?string $utc): ?float
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        return (time() - (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->getTimestamp()) / 3600;
    }

    /** Link to a specific line of a file on GitHub (or the plain URL). */
    public static function lineUrl(?string $viewUrl, ?int $line): ?string
    {
        if ($viewUrl === null || $viewUrl === '') {
            return null;
        }
        return $line ? $viewUrl . '#L' . $line : $viewUrl;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'ok' => 'OK',
            'warning' => 'Warnung',
            'error' => 'Fehler',
            'ignored' => 'Ignoriert',
            'pending' => 'Ungeprüft',
            default => $status,
        };
    }

    public static function severity(string $status): int
    {
        return match ($status) {
            'error' => 4,
            'warning' => 3,
            'pending' => 2,
            'ok' => 1,
            default => 0,
        };
    }
}
