<?php

declare(strict_types=1);

if (!function_exists('h')) {
    /** HTML-escape a value for output. */
    function h(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
