<?php

declare(strict_types=1);

namespace LinkWatcher\Parser;

final class UrlExtractor
{
    /** @return list<string> unique http(s) URLs found in the text */
    public static function extract(string $text): array
    {
        if (!preg_match_all('~https?://[^\s"\'<>`{}|\\\\^\[\]]+~iu', $text, $matches)) {
            return [];
        }

        $urls = [];
        foreach ($matches[0] as $url) {
            $url = self::trimTrailing($url);
            if (parse_url($url, PHP_URL_HOST)) {
                $urls[$url] = true;
            }
        }
        return array_keys($urls);
    }

    private static function trimTrailing(string $url): string
    {
        while (true) {
            $trimmed = rtrim($url, '.,;:!?*');
            // Drop a closing parenthesis only if it is not balanced inside the URL, e.g. "(see https://x.ch/a)"
            if (str_ends_with($trimmed, ')') && substr_count($trimmed, '(') < substr_count($trimmed, ')')) {
                $trimmed = substr($trimmed, 0, -1);
            }
            if ($trimmed === $url) {
                return $url;
            }
            $url = $trimmed;
        }
    }
}
