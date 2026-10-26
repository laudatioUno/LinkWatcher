<?php

declare(strict_types=1);

namespace LinkWatcher\Parser;

final class ParserFactory
{
    public static function for(string $format): ParserInterface
    {
        return match ($format) {
            'ios_strings' => new IosStringsParser(),
            'android_xml' => new AndroidXmlParser(),
            default => throw new \InvalidArgumentException("Unbekanntes Format '{$format}' (erlaubt: ios_strings, android_xml, auto)."),
        };
    }
}
