<?php

declare(strict_types=1);

namespace LinkWatcher\Parser;

/**
 * Parses Apple .strings files:  "key" = "value";
 * Uses a small tokenizer so that "//" inside values (URLs!) is never treated as a comment.
 */
final class IosStringsParser implements ParserInterface
{
    public function parse(string $content): array
    {
        $content = self::toUtf8($content);
        $tokens = $this->tokenize($content);

        $entries = [];
        $count = count($tokens);
        for ($i = 0; $i + 3 < $count; $i++) {
            if ($tokens[$i]['type'] === 'string'
                && $tokens[$i + 1]['type'] === '='
                && $tokens[$i + 2]['type'] === 'string'
                && $tokens[$i + 3]['type'] === ';') {
                $entries[] = [
                    'key' => $tokens[$i]['value'],
                    'value' => $tokens[$i + 2]['value'],
                    'line' => $tokens[$i]['line'],
                ];
                $i += 3;
            }
        }
        return $entries;
    }

    /** @return list<array{type:string,value?:string,line:int}> */
    private function tokenize(string $s): array
    {
        $tokens = [];
        $len = strlen($s);
        $line = 1;
        $i = 0;

        while ($i < $len) {
            $c = $s[$i];

            if ($c === "\n") {
                $line++;
                $i++;
                continue;
            }
            if ($c === '/' && ($s[$i + 1] ?? '') === '/') {
                while ($i < $len && $s[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($c === '/' && ($s[$i + 1] ?? '') === '*') {
                $end = strpos($s, '*/', $i + 2);
                $end = $end === false ? $len : $end + 2;
                $line += substr_count($s, "\n", $i, $end - $i);
                $i = $end;
                continue;
            }
            if ($c === '"') {
                $startLine = $line;
                $i++;
                $buf = '';
                while ($i < $len && $s[$i] !== '"') {
                    if ($s[$i] === '\\' && $i + 1 < $len) {
                        $buf .= $s[$i] . $s[$i + 1];
                        if ($s[$i + 1] === "\n") {
                            $line++;
                        }
                        $i += 2;
                        continue;
                    }
                    if ($s[$i] === "\n") {
                        $line++;
                    }
                    $buf .= $s[$i];
                    $i++;
                }
                $i++; // closing quote
                $tokens[] = ['type' => 'string', 'value' => self::unescape($buf), 'line' => $startLine];
                continue;
            }
            if ($c === '=' || $c === ';') {
                $tokens[] = ['type' => $c, 'line' => $line];
                $i++;
                continue;
            }
            $i++; // whitespace or anything unexpected
        }
        return $tokens;
    }

    private static function unescape(string $s): string
    {
        return preg_replace_callback(
            '/\\\\(U[0-9a-fA-F]{4}|u[0-9a-fA-F]{4}|.)/s',
            static function (array $m): string {
                $seq = $m[1];
                if (strlen($seq) === 5) {
                    return mb_chr((int) hexdec(substr($seq, 1)), 'UTF-8') ?: '';
                }
                return match ($seq) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    default => $seq,
                };
            },
            $s
        ) ?? $s;
    }

    /** .strings files are sometimes UTF-16; normalise to UTF-8 and drop the BOM. */
    private static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE")) {
            return mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($content, "\xFE\xFF")) {
            return mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }
        return $content;
    }
}
