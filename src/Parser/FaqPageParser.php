<?php

declare(strict_types=1);

namespace LinkWatcher\Parser;

/**
 * Reads FAQ pages of the swiyu website (Next.js, server-rendered accordion).
 *
 * Structure relied upon (only semantic hooks, not the hashed CSS-module classes):
 *   <section id="…"><h2 class="section-title-label">Section</h2>
 *     <div class="content-type--faq" data-doc-id="466">
 *       <div id="faq-466-doc-1jqoaaah20" class="… c--faq-container">
 *         <h3><button>…Question…</button></h3>
 *         <div class="… accordion-item-content"> answer with <a href> </div>
 */
final class FaqPageParser
{
    /**
     * @return list<array{key:string,value:string,line:null,title:string,section:string,category:string,link_url:string,urls:list<string>}>
     */
    public function parse(string $html, string $pageUrl): array
    {
        $xp = self::xpath($html);
        $pageUrl = self::stripFragment($pageUrl);
        $category = self::text($xp->query('//h1')->item(0));

        $containers = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' c--faq-container ')]");
        if ($containers->length === 0) {
            // Fallback in case the class hook changes but the id scheme stays.
            $containers = $xp->query("//*[starts-with(@id, 'faq-') and contains(@id, '-doc-')]");
        }

        $entries = [];
        foreach ($containers as $item) {
            /** @var \DOMElement $item */
            $id = $item->getAttribute('id');
            $docId = null;
            $anchor = null;
            if (preg_match('/^faq-(\d+)-(doc-[a-z0-9]+)$/i', $id, $m)) {
                $docId = $m[1];
                $anchor = $m[2];
            }
            if ($docId === null) {
                $holder = $xp->query('ancestor-or-self::*[@data-doc-id][1]', $item)->item(0);
                $docId = $holder instanceof \DOMElement ? $holder->getAttribute('data-doc-id') : null;
            }

            $title = self::text($xp->query('.//h3[1]', $item)->item(0));
            $answer = $xp->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' accordion-item-content ')]", $item)->item(0)
                ?? $xp->query(".//*[@role='region' or starts-with(@id, 'accordion-panel-')][1]", $item)->item(0);
            if ($title === '' || $answer === null) {
                continue;
            }
            $sectionNode = $xp->query('ancestor::section[1]//h2[1]', $item)->item(0);

            $urls = [];
            foreach ($xp->query('.//a[@href]', $answer) as $a) {
                /** @var \DOMElement $a */
                $url = self::resolve(trim($a->getAttribute('href')), $pageUrl);
                if ($url !== null) {
                    $urls[$url] = true;
                }
            }
            $urls = array_keys($urls);

            $key = $docId !== null && $docId !== '' ? 'faq-' . $docId : 'faq-' . substr(sha1($title), 0, 10);
            $text = self::text($answer);
            $entries[] = [
                'key' => $key,
                'value' => $text . ($urls ? "\n\n" . implode("\n", $urls) : ''),
                'line' => null,
                'title' => $title,
                'section' => self::text($sectionNode),
                'category' => $category,
                'link_url' => $pageUrl . ($anchor ? '#' . $anchor : ''),
                'urls' => $urls,
            ];
        }
        return $entries;
    }

    /**
     * Category pages linked from a help start page: same host, path = start path + "-…"
     * (e.g. /de/hilfe → /de/hilfe-e-id, /fr/aide → /fr/aide-e-id).
     *
     * @return list<string>
     */
    public function discover(string $html, string $startUrl): array
    {
        $xp = self::xpath($html);
        $start = parse_url($startUrl);
        $host = strtolower($start['host'] ?? '');
        $prefix = rtrim($start['path'] ?? '/', '/') . '-';

        $pages = [];
        foreach ($xp->query('//a[@href]') as $a) {
            /** @var \DOMElement $a */
            $url = self::resolve($a->getAttribute('href'), $startUrl);
            if ($url === null) {
                continue;
            }
            $p = parse_url($url);
            if (strtolower($p['host'] ?? '') === $host && str_starts_with($p['path'] ?? '', $prefix)) {
                $pages[self::stripFragment(strtok($url, '?'))] = true;
            }
        }
        return array_keys($pages);
    }

    /** Makes an href absolute; returns null for non-http links (mailto:, tel:, #top …). */
    public static function resolve(string $href, string $base): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || $href[0] === '#') {
            return null;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) {
            return preg_match('~^https?://~i', $href) ? $href : null;
        }
        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return ($b['scheme'] ?? 'https') . ':' . $href;
        }
        if ($href[0] === '/') {
            return $origin . $href;
        }
        $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/');
        return $origin . $dir . $href;
    }

    private static function stripFragment(string $url): string
    {
        $pos = strpos($url, '#');
        return $pos === false ? $url : substr($url, 0, $pos);
    }

    private static function xpath(string $html): \DOMXPath
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($doc);
    }

    /** Visible text of a node: icon ligatures, scripts and screen-reader hints removed, whitespace collapsed. */
    private static function text(?\DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }
        $clone = $node->cloneNode(true);
        $xp = new \DOMXPath($node->ownerDocument);
        $remove = [];
        foreach ($xp->query(".//*[contains(@class, 'material-symbols') or contains(concat(' ', normalize-space(@class), ' '), ' sr-only ') or self::script or self::style or self::svg or self::button[not(ancestor::h3)]]", $clone) as $n) {
            $remove[] = $n;
        }
        foreach ($remove as $n) {
            $n->parentNode?->removeChild($n);
        }
        // Keep paragraphs apart.
        $text = '';
        foreach ($clone->childNodes as $child) {
            $text .= ' ' . $child->textContent;
        }
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
