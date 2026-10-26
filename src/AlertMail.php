<?php

declare(strict_types=1);

namespace LinkWatcher;

/** Builds the nightly summary mail (one mail per run with all problems of that run). */
final class AlertMail
{
    public function __construct(private Config $config)
    {
    }

    /**
     * @param array{run_id:int,started_at:string,errors:list<array>,warnings:list<array>,recovered:list<array>,
     *              source_errors:list<array>,sync:array,counts:array} $d
     * @return array{0:string,1:string,2:string} subject, text, html
     */
    public function build(array $d): array
    {
        $errors = $d['errors'];
        $newCount = count(array_filter($errors, static fn(array $e): bool => $e['is_new']));
        $parts = [];
        if ($errors) {
            $parts[] = count($errors) . ' defekte URL' . (count($errors) === 1 ? '' : 's') . ($newCount ? " ({$newCount} neu)" : '');
        }
        if ($d['source_errors']) {
            $parts[] = count($d['source_errors']) . ' Quelldatei' . (count($d['source_errors']) === 1 ? '' : 'en') . ' nicht lesbar';
        }
        if (!$parts && $d['warnings']) {
            $parts[] = count($d['warnings']) . ' Warnung' . (count($d['warnings']) === 1 ? '' : 'en');
        }
        $subject = implode(', ', $parts) . ' – ' . Util::fmt($d['started_at'], 'd.m.Y');

        return [$subject, $this->text($d), $this->html($d)];
    }

    private function link(string $query): ?string
    {
        $base = rtrim((string) $this->config->get('app.base_url'), '/');
        return $base !== '' ? $base . '/?' . $query : null;
    }

    private function text(array $d): string
    {
        $out = 'LinkWatcher – Prüflauf vom ' . Util::fmt($d['started_at']) . "\n";
        $out .= str_repeat('=', 50) . "\n\n";
        $c = $d['counts'];
        $out .= "Geprüft: {$c['checked']} URLs – {$c['ok']} OK, {$c['warning']} Warnungen, {$c['error']} Fehler\n\n";

        if ($d['source_errors']) {
            $out .= "QUELLDATEIEN MIT PROBLEMEN\n";
            foreach ($d['source_errors'] as $s) {
                $out .= "- {$s['label']} [{$s['language']}]: {$s['error']}\n";
            }
            $out .= "\n";
        }

        foreach (['errors' => 'DEFEKTE URLS', 'warnings' => 'WARNUNGEN'] as $section => $title) {
            if (!$d[$section]) {
                continue;
            }
            $out .= "{$title}\n";
            foreach ($d[$section] as $e) {
                $out .= "\n" . ($e['is_new'] ?? false ? '[NEU] ' : '') . $e['url'] . "\n";
                $out .= "  → {$e['message']}";
                if ($section === 'errors' && !($e['is_new'] ?? false) && $e['failing_since']) {
                    $out .= ' (seit ' . Util::fmt($e['failing_since'], 'd.m.Y') . ')';
                }
                $out .= "\n";
                foreach ($e['keys'] as $k) {
                    $out .= "  • {$k['key_name']} – {$k['label']} [{$k['language']}]";
                    $line = Util::lineUrl($k['view_url'], $k['line'] ? (int) $k['line'] : null);
                    $out .= $line ? "\n    {$line}\n" : "\n";
                }
            }
            $out .= "\n";
        }

        if ($d['recovered']) {
            $out .= "WIEDER ERREICHBAR\n";
            foreach ($d['recovered'] as $r) {
                $out .= "- {$r['url']}\n";
            }
            $out .= "\n";
        }

        $out .= $this->syncText($d['sync']);
        if ($url = $this->link('p=run&id=' . $d['run_id'])) {
            $out .= "\nDetails: {$url}\n";
        }
        return $out;
    }

    private function syncText(array $sync): string
    {
        $out = '';
        if ($sync['new_keys'] ?? []) {
            $out .= "Neue Textkeys: " . implode(', ', $sync['new_keys']) . "\n";
        }
        if ($sync['archived_keys'] ?? []) {
            $out .= "Archivierte Textkeys: " . implode(', ', $sync['archived_keys']) . "\n";
        }
        return $out;
    }

    private function html(array $d): string
    {
        $c = $d['counts'];
        $red = '#c0362c';
        $amber = '#b26b00';
        $green = '#2e7d4f';

        $h = '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d2433;">';
        $h .= '<div style="max-width:760px;margin:0 auto;background:#fff;border-radius:10px;padding:28px;border:1px solid #e3e6ec;">';
        $h .= '<div style="font-size:13px;color:#6b7385;letter-spacing:.04em;text-transform:uppercase;">LinkWatcher · Prüflauf vom ' . h(Util::fmt($d['started_at'])) . '</div>';
        $h .= '<h1 style="font-size:22px;margin:6px 0 18px;">' . h($this->headline($d)) . '</h1>';

        $h .= '<table cellpadding="0" cellspacing="0" style="width:100%;margin-bottom:22px;"><tr>';
        foreach ([['Geprüft', $c['checked'], '#1d2433'], ['OK', $c['ok'], $green], ['Warnungen', $c['warning'], $amber], ['Fehler', $c['error'], $red]] as [$label, $num, $color]) {
            $h .= '<td style="padding:10px 12px;background:#f7f8fa;border-radius:8px;text-align:center;border:4px solid #fff;">'
                . '<div style="font-size:22px;font-weight:700;color:' . $color . ';">' . (int) $num . '</div>'
                . '<div style="font-size:12px;color:#6b7385;">' . $label . '</div></td>';
        }
        $h .= '</tr></table>';

        if ($d['source_errors']) {
            $h .= $this->sectionTitle('Quelldateien mit Problemen', $red);
            foreach ($d['source_errors'] as $s) {
                $h .= '<div style="padding:10px 12px;border-left:4px solid ' . $red . ';background:#fdf3f2;margin-bottom:8px;border-radius:4px;">'
                    . '<strong>' . h($s['label']) . ' [' . h($s['language']) . ']</strong><br><span style="color:#4a5163;">' . h($s['error']) . '</span>'
                    . ($s['view_url'] ? '<br><a href="' . h($s['view_url']) . '" style="color:#2754c5;font-size:13px;">Datei auf GitHub</a>' : '')
                    . '</div>';
            }
        }

        foreach (['errors' => ['Defekte URLs', $red, '#fdf3f2'], 'warnings' => ['Warnungen', $amber, '#fff8ec']] as $section => [$title, $color, $bg]) {
            if (!$d[$section]) {
                continue;
            }
            $h .= $this->sectionTitle($title . ' (' . count($d[$section]) . ')', $color);
            foreach ($d[$section] as $e) {
                $badge = ($e['is_new'] ?? false)
                    ? '<span style="background:' . $color . ';color:#fff;font-size:11px;font-weight:700;padding:2px 6px;border-radius:4px;margin-right:6px;">NEU</span>'
                    : '';
                $since = ($section === 'errors' && !($e['is_new'] ?? false) && $e['failing_since'])
                    ? ' <span style="color:#6b7385;">· seit ' . h(Util::fmt($e['failing_since'], 'd.m.Y')) . '</span>' : '';
                $detail = $this->link('p=url&id=' . $e['url_id']);

                $h .= '<div style="padding:12px 14px;border-left:4px solid ' . $color . ';background:' . $bg . ';margin-bottom:10px;border-radius:4px;">';
                $h .= '<div style="word-break:break-all;">' . $badge . '<a href="' . h($e['url']) . '" style="color:#1d2433;font-weight:600;">' . h($e['url']) . '</a></div>';
                $h .= '<div style="margin:4px 0 8px;color:' . $color . ';font-weight:600;font-size:14px;">' . h($e['message']) . $since . '</div>';
                $h .= '<table cellpadding="0" cellspacing="0" style="font-size:13px;width:100%;">';
                foreach ($e['keys'] as $k) {
                    $line = Util::lineUrl($k['view_url'], $k['line'] ? (int) $k['line'] : null);
                    $h .= '<tr><td style="padding:2px 0;font-family:SFMono-Regular,Consolas,monospace;">' . h($k['key_name']) . '</td>'
                        . '<td style="padding:2px 8px;color:#4a5163;white-space:nowrap;">' . h($k['label']) . ' · ' . h(strtoupper($k['language'])) . '</td>'
                        . '<td style="padding:2px 0;text-align:right;white-space:nowrap;">' . ($line ? '<a href="' . h($line) . '" style="color:#2754c5;">GitHub ↗</a>' : '') . '</td></tr>';
                }
                $h .= '</table>';
                if ($detail) {
                    $h .= '<div style="margin-top:6px;font-size:12px;"><a href="' . h($detail) . '" style="color:#2754c5;">Verlauf im Dashboard</a></div>';
                }
                $h .= '</div>';
            }
        }

        if ($d['recovered']) {
            $h .= $this->sectionTitle('Wieder erreichbar', $green);
            $h .= '<ul style="margin:0 0 16px;padding-left:18px;font-size:14px;">';
            foreach ($d['recovered'] as $r) {
                $h .= '<li style="word-break:break-all;">' . h($r['url']) . '</li>';
            }
            $h .= '</ul>';
        }

        $sync = $d['sync'];
        if (($sync['new_keys'] ?? []) || ($sync['archived_keys'] ?? [])) {
            $h .= $this->sectionTitle('Änderungen an Textkeys', '#4a5163');
            $h .= '<div style="font-size:13px;color:#4a5163;">';
            if ($sync['new_keys']) {
                $h .= '<p style="margin:0 0 6px;"><strong>Neu:</strong> ' . h(implode(', ', $sync['new_keys'])) . '</p>';
            }
            if ($sync['archived_keys']) {
                $h .= '<p style="margin:0 0 6px;"><strong>Archiviert:</strong> ' . h(implode(', ', $sync['archived_keys'])) . '</p>';
            }
            $h .= '</div>';
        }

        if ($url = $this->link('p=run&id=' . $d['run_id'])) {
            $h .= '<p style="margin:24px 0 0;"><a href="' . h($url) . '" style="display:inline-block;background:#1d2433;color:#fff;text-decoration:none;padding:10px 16px;border-radius:6px;font-weight:600;">Zum Dashboard</a></p>';
        }
        $h .= '</div><p style="text-align:center;color:#9aa1b1;font-size:12px;">LinkWatcher ' . LW_VERSION . '</p></body></html>';

        return $h;
    }

    private function headline(array $d): string
    {
        if ($d['errors']) {
            $n = count($d['errors']);
            return $n === 1 ? '1 URL ist nicht erreichbar' : "{$n} URLs sind nicht erreichbar";
        }
        if ($d['source_errors']) {
            return 'Quelldateien konnten nicht gelesen werden';
        }
        return 'Warnungen beim Prüflauf';
    }

    private function sectionTitle(string $title, string $color): string
    {
        return '<h2 style="font-size:15px;margin:22px 0 10px;color:' . $color . ';border-bottom:1px solid #e3e6ec;padding-bottom:6px;">' . h($title) . '</h2>';
    }
}
