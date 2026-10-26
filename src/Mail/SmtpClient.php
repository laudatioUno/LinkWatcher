<?php

declare(strict_types=1);

namespace LinkWatcher\Mail;

/** Minimal SMTP client: implicit TLS (465), STARTTLS (587) or plain; AUTH PLAIN/LOGIN. */
final class SmtpClient
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(private array $cfg)
    {
    }

    /** @param array<string,string> $headers */
    public function send(string $from, array $to, array $headers, string $body): void
    {
        $host = (string) $this->cfg['host'];
        $port = (int) ($this->cfg['port'] ?? 587);
        $encryption = strtolower((string) ($this->cfg['encryption'] ?? 'tls'));
        $timeout = (int) ($this->cfg['timeout'] ?? 20);
        $verify = (bool) ($this->cfg['verify_tls'] ?? true);

        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'peer_name' => $host,
        ]]);
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException("SMTP-Verbindung zu {$host}:{$port} fehlgeschlagen: {$errstr} ({$errno})");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $timeout);

        try {
            $this->expect(220);
            $ehlo = $this->hello();

            if ($encryption === 'tls') {
                if (!str_contains($ehlo, 'STARTTLS')) {
                    throw new \RuntimeException('SMTP-Server bietet kein STARTTLS an.');
                }
                $this->command('STARTTLS', 220);
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                if (!@stream_socket_enable_crypto($socket, true, $method)) {
                    throw new \RuntimeException('STARTTLS-Handshake fehlgeschlagen.');
                }
                $ehlo = $this->hello();
            }

            $user = (string) ($this->cfg['username'] ?? '');
            if ($user !== '') {
                $pass = (string) ($this->cfg['password'] ?? '');
                if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $ehlo)) {
                    $this->command('AUTH PLAIN ' . base64_encode("\0{$user}\0{$pass}"), 235, 'Anmeldung am SMTP-Server fehlgeschlagen');
                } else {
                    $this->command('AUTH LOGIN', 334);
                    $this->command(base64_encode($user), 334);
                    $this->command(base64_encode($pass), 235, 'Anmeldung am SMTP-Server fehlgeschlagen');
                }
            }

            $this->command("MAIL FROM:<{$from}>", 250);
            foreach ($to as $rcpt) {
                $this->command("RCPT TO:<{$rcpt}>", [250, 251]);
            }
            $this->command('DATA', 354);

            $data = '';
            foreach ($headers as $name => $value) {
                $data .= "{$name}: {$value}\r\n";
            }
            $data .= "\r\n" . $body;
            $data = preg_replace("/\r?\n/", "\r\n", $data);
            $data = preg_replace('/^\./m', '..', $data); // dot-stuffing
            $this->write(rtrim($data, "\r\n") . "\r\n.\r\n");
            $this->expect(250);

            $this->command('QUIT', 221);
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    private function hello(): string
    {
        $name = gethostname() ?: 'localhost';
        return $this->command("EHLO {$name}", 250);
    }

    /** @param int|list<int> $expected */
    private function command(string $cmd, int|array $expected, ?string $errorText = null): string
    {
        $this->write($cmd . "\r\n");
        return $this->expect($expected, $errorText, str_starts_with($cmd, 'AUTH') ? 'AUTH …' : $cmd);
    }

    private function write(string $data): void
    {
        if (fwrite($this->socket, $data) === false) {
            throw new \RuntimeException('SMTP: Schreiben auf die Verbindung fehlgeschlagen.');
        }
    }

    /** @param int|list<int> $expected */
    private function expect(int|array $expected, ?string $errorText = null, string $cmd = ''): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, (array) $expected, true)) {
            $detail = trim($response) !== '' ? trim($response) : 'keine Antwort (Timeout?)';
            throw new \RuntimeException(($errorText ?? 'SMTP-Fehler') . ($cmd !== '' && $errorText === null ? " bei '{$cmd}'" : '') . ": {$detail}");
        }
        return $response;
    }
}
