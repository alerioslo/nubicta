<?php

namespace App\Services\Mail;

use RuntimeException;

/**
 * Cliente IMAP4rev1 minimo (sin la extension php-imap): LOGIN, SELECT, UID SEARCH, UID FETCH y UID STORE.
 * Se usa para leer el buzon de recepcion de facturas electronicas de proveedores.
 */
class ImapMailbox
{
    /** @var resource|null */
    private $socket = null;

    private int $tag = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly int $timeout = 30,
    ) {}

    public function connect(string $username, string $password): void
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://').$this->host.':'.$this->port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $this->host]]);
        $socket = @stream_socket_client($remote, $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (! $socket) {
            throw new RuntimeException("No fue posible conectar con el servidor IMAP {$this->host}:{$this->port} ($error).");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $greeting = $this->line();
        if (! str_starts_with($greeting, '* OK')) {
            throw new RuntimeException('El servidor IMAP respondió: '.trim($greeting));
        }
        if ($this->encryption === 'tls') {
            $this->command('STARTTLS');
            if (! stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('No fue posible iniciar TLS con el servidor IMAP.');
            }
        }
        $this->command('LOGIN '.$this->quote($username).' '.$this->quote($password));
    }

    /** Devuelve el UIDVALIDITY de la carpeta: si cambia, los UID anteriores ya no sirven. */
    public function select(string $folder = 'INBOX'): string
    {
        foreach ($this->command('SELECT '.$this->quote($folder)) as $line) {
            if (preg_match('/UIDVALIDITY (\d+)/', $line, $m)) {
                return $m[1];
            }
        }

        return '';
    }

    /** @return list<int> UIDs que cumplen el criterio IMAP (p. ej. "UID 120:*" o "SINCE 01-Jan-2026") */
    public function search(string $criteria): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH '.$criteria) as $line) {
            if (str_starts_with($line, '* SEARCH')) {
                $uids = array_map('intval', array_filter(explode(' ', trim(substr($line, 8)))));
            }
        }

        return $uids;
    }

    /** Mensaje completo (cabeceras + cuerpo) sin marcarlo como leido. */
    public function fetch(int $uid): string
    {
        $tag = $this->send("UID FETCH $uid BODY.PEEK[]");
        $raw = '';
        while (true) {
            $line = $this->line();
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                $raw = $this->read((int) $m[1]);
            } elseif (str_starts_with($line, "$tag ")) {
                $this->assertOk($line);

                return $raw;
            }
        }
    }

    public function logout(): void
    {
        if ($this->socket) {
            try {
                $this->command('LOGOUT');
            } catch (RuntimeException) {
            }
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /** @return list<string> lineas no etiquetadas de la respuesta */
    private function command(string $command): array
    {
        $tag = $this->send($command);
        $lines = [];
        while (true) {
            $line = $this->line();
            if (str_starts_with($line, "$tag ")) {
                $this->assertOk($line);

                return $lines;
            }
            $lines[] = rtrim($line);
        }
    }

    private function send(string $command): string
    {
        $tag = 'A'.str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
        fwrite($this->socket, "$tag $command\r\n");

        return $tag;
    }

    private function assertOk(string $line): void
    {
        if (! preg_match('/^\S+ OK/', $line)) {
            throw new RuntimeException('Error IMAP: '.trim(preg_replace('/^\S+ (NO|BAD) /', '', $line)));
        }
    }

    private function line(): string
    {
        $line = fgets($this->socket);
        if ($line === false) {
            throw new RuntimeException('El servidor IMAP cerró la conexión o no respondió a tiempo.');
        }

        return $line;
    }

    private function read(int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = fread($this->socket, $bytes - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Se interrumpió la descarga del mensaje IMAP.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }

    /**
     * Adjuntos ZIP o XML de un mensaje MIME (recorre las partes multipart de forma recursiva).
     *
     * @return list<array{name:string, content:string}>
     */
    public static function attachments(string $raw): array
    {
        [$head, $body] = array_pad(preg_split("/\r?\n\r?\n/", $raw, 2), 2, '');
        $headers = self::headers($head);
        $type = $headers['content-type'] ?? 'text/plain';
        if (stripos($type, 'multipart/') === 0 && preg_match('/boundary="?([^";]+)"?/i', $type, $m)) {
            $out = [];
            foreach (preg_split('/\r?\n--'.preg_quote($m[1], '/').'(?:--)?\s*\r?\n/', "\n".$body) as $part) {
                if (trim($part) !== '' && trim($part) !== '--') {
                    array_push($out, ...self::attachments(ltrim($part, "\r\n")));
                }
            }

            return $out;
        }
        $name = '';
        foreach ([$headers['content-disposition'] ?? '', $type] as $h) {
            if (preg_match('/(?:filename|name)\*?="?([^";]+)"?/i', $h, $m)) {
                $name = iconv_mime_decode($m[1], ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $m[1];
                break;
            }
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($ext, ['zip', 'xml'], true)) {
            return [];
        }
        $content = match (strtolower(trim($headers['content-transfer-encoding'] ?? ''))) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };

        return [['name' => $name, 'content' => $content]];
    }

    /** @return array<string, string> */
    private static function headers(string $head): array
    {
        $out = [];
        foreach (preg_split("/\r?\n(?![ \t])/", $head) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $out[strtolower(trim($k))] = trim(preg_replace('/\r?\n[ \t]+/', ' ', $v));
            }
        }

        return $out;
    }

    public static function sender(string $raw): string
    {
        [$head] = preg_split("/\r?\n\r?\n/", $raw, 2);

        return mb_substr(iconv_mime_decode(self::headers($head)['from'] ?? '', ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: '', 0, 255);
    }
}
