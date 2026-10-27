<?php

declare(strict_types=1);

namespace LinkWatcher\Parser;

/**
 * Parses Android resource files:  <string name="key">value</string>
 * Also covers <string-array> (key[0], key[1] …) and <plurals> (key[one], key[other] …).
 * The value keeps inner markup, so URLs in <a href="…"> are found as well.
 */
final class AndroidXmlParser implements ParserInterface
{
    public function parse(string $content): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($content, LIBXML_NONET | LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok) {
            $msg = $errors ? trim($errors[0]->message) . ' (Zeile ' . $errors[0]->line . ')' : 'unbekannter Fehler';
            throw new \RuntimeException('Ungültiges XML: ' . $msg);
        }

        $entries = [];
        $root = $doc->documentElement;
        if ($root === null) {
            return [];
        }

        foreach ($root->childNodes as $node) {
            if (!$node instanceof \DOMElement || !$node->hasAttribute('name')) {
                continue;
            }
            $name = $node->getAttribute('name');

            if ($node->tagName === 'string') {
                $entries[] = ['key' => $name, 'value' => $this->value($node), 'line' => $node->getLineNo()];
                continue;
            }

            if ($node->tagName === 'string-array' || $node->tagName === 'plurals') {
                $index = 0;
                foreach ($node->childNodes as $item) {
                    if (!$item instanceof \DOMElement || $item->tagName !== 'item') {
                        continue;
                    }
                    $suffix = $item->hasAttribute('quantity') ? $item->getAttribute('quantity') : (string) $index;
                    $entries[] = [
                        'key' => $name . '[' . $suffix . ']',
                        'value' => $this->value($item),
                        'line' => $item->getLineNo(),
                    ];
                    $index++;
                }
            }
        }
        return $entries;
    }

    private function value(\DOMElement $el): string
    {
        $inner = '';
        foreach ($el->childNodes as $child) {
            $inner .= $el->ownerDocument->saveXML($child);
        }
        $inner = html_entity_decode($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Android escapes: \' \" \n \t \@ \?
        return preg_replace_callback('/\\\\(.)/s', static fn(array $m): string => match ($m[1]) {
            'n' => "\n",
            't' => "\t",
            default => $m[1],
        }, $inner) ?? $inner;
    }
}
