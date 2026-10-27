<?php

declare(strict_types=1);

namespace LinkWatcher\Mail;

use LinkWatcher\Config;

/** Sends a multipart (text + HTML) mail via SMTP or PHP's mail(). No external dependencies. */
final class Mailer
{
    public function __construct(private array $cfg)
    {
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->get('mail'));
    }

    /** @return list<string> */
    public function recipients(): array
    {
        $to = $this->cfg['to'] ?? [];
        return array_values(array_filter(array_map('trim', is_array($to) ? $to : explode(',', (string) $to))));
    }

    public function isConfigured(): bool
    {
        if ($this->recipients() === [] || empty($this->cfg['from'])) {
            return false;
        }
        return ($this->cfg['transport'] ?? 'smtp') !== 'smtp' || !empty($this->cfg['smtp']['host']);
    }

    public function send(string $subject, string $text, ?string $html = null): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Mailversand ist nicht konfiguriert (mail.from, mail.to und ggf. mail.smtp.host fehlen).');
        }
        $to = $this->recipients();
        $prefix = trim((string) ($this->cfg['subject_prefix'] ?? ''));
        $subject = $prefix !== '' ? "{$prefix} {$subject}" : $subject;

        [$headers, $body] = $this->build($to, $subject, $text, $html);

        match ($this->cfg['transport'] ?? 'smtp') {
            'smtp' => (new SmtpClient($this->cfg['smtp']))->send((string) $this->cfg['from'], $to, $headers, $body),
            'mail' => $this->sendViaMail($to, $subject, $headers, $body),
            default => throw new \RuntimeException("Unbekannter Mail-Transport '{$this->cfg['transport']}' (smtp oder mail)."),
        };
    }

    /** @return array{0:array<string,string>,1:string} */
    private function build(array $to, string $subject, string $text, ?string $html): array
    {
        $from = (string) $this->cfg['from'];
        $fromName = (string) ($this->cfg['from_name'] ?? '');
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);

        $headers = [
            'Date' => date('r'),
            'From' => $fromName !== '' ? self::encodeHeader($fromName) . " <{$from}>" : $from,
            'To' => implode(', ', $to),
            'Subject' => self::encodeHeader($subject),
            'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'X-Mailer' => 'LinkWatcher ' . LW_VERSION,
        ];

        if ($html === null) {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'base64';
            return [$headers, chunk_split(base64_encode($text))];
        }

        $boundary = 'lw_' . bin2hex(random_bytes(10));
        $headers['Content-Type'] = "multipart/alternative; boundary=\"{$boundary}\"";
        $body = "This is a multi-part message in MIME format.\r\n\r\n"
            . "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . "--{$boundary}--\r\n";

        return [$headers, $body];
    }

    private function sendViaMail(array $to, string $subject, array $headers, string $body): void
    {
        unset($headers['To'], $headers['Subject']);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        if (!mail(implode(', ', $to), self::encodeHeader($subject), $body, implode("\r\n", $headerLines))) {
            throw new \RuntimeException('mail() hat den Versand abgelehnt.');
        }
    }

    public static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n") : $value;
    }
}
