<?php

namespace FSImap\App\Services;

class ImapConnector
{
    private $connection = null;

    public function connect($config)
    {
        if (!extension_loaded('imap')) {
            return new \WP_Error('imap_missing', __('PHP IMAP extension is not installed on the server.', 'fluent-support-imap'));
        }

        $host = sanitize_text_field($config['host'] ?? '');
        $port = intval($config['port'] ?? 993);
        $username = $config['username'] ?? '';
        $password = self::decryptPassword($config['password'] ?? '');
        $encryption = $config['encryption'] ?? 'ssl';

        if (empty($host) || empty($username) || empty($password)) {
            return new \WP_Error('imap_config', __('IMAP configuration incomplete.', 'fluent-support-imap'));
        }

        $flags = '/imap';
        if ($encryption === 'ssl') {
            $flags .= '/ssl';
        } elseif ($encryption === 'tls') {
            $flags .= '/tls';
        } else {
            $flags .= '/notls';
        }
        $flags .= '/novalidate-cert';

        $mailbox = '{' . $host . ':' . $port . $flags . '}INBOX';

        imap_timeout(IMAP_OPENTIMEOUT, 15);
        imap_timeout(IMAP_READTIMEOUT, 15);

        $this->connection = @imap_open($mailbox, $username, $password, 0, 1);

        if (!$this->connection) {
            $error = imap_last_error();
            imap_errors();
            imap_alerts();
            return new \WP_Error('imap_connect', sprintf(__('IMAP connection failed: %s', 'fluent-support-imap'), $error));
        }

        return $this->connection;
    }

    public function testConnection($config)
    {
        $result = $this->connect($config);

        if (is_wp_error($result)) {
            return $result;
        }

        $info = imap_num_msg($this->connection);
        $this->disconnect();

        return [
            'success'       => true,
            'message'       => __('IMAP connection successful!', 'fluent-support-imap'),
            'total_emails'  => $info,
        ];
    }

    public function fetchUnreadEmails($limit = 20)
    {
        if (!$this->connection) {
            return new \WP_Error('no_connection', __('No active IMAP connection.', 'fluent-support-imap'));
        }

        $emails = imap_search($this->connection, 'UNSEEN');

        if ($emails === false) {
            $error = imap_last_error();
            imap_errors();
            imap_alerts();
            if ($error) {
                return new \WP_Error('imap_search', sprintf(__('Email search error: %s', 'fluent-support-imap'), $error));
            }
            return [];
        }

        // Limita il numero di email da processare
        $emails = array_slice($emails, 0, $limit);
        $parsedEmails = [];

        foreach ($emails as $msgNum) {
            $parsed = $this->parseEmail($msgNum);
            if ($parsed && !is_wp_error($parsed)) {
                $parsedEmails[] = $parsed;
            }
        }

        return $parsedEmails;
    }

    public function parseEmail($msgNum)
    {
        if (!$this->connection) {
            return new \WP_Error('no_connection', __('No active IMAP connection.', 'fluent-support-imap'));
        }

        $header = @imap_headerinfo($this->connection, $msgNum);
        if (!$header) {
            return new \WP_Error('parse_error', sprintf(__('Cannot read email headers #%d', 'fluent-support-imap'), $msgNum));
        }

        $structure = imap_fetchstructure($this->connection, $msgNum);

        // Subject
        $subject = isset($header->subject) ? $this->decodeMimeStr($header->subject) : '(Nessun oggetto)';

        // From
        $from = $this->parseAddressList($header->from ?? []);

        // To
        $to = $this->parseAddressList($header->to ?? []);

        // CC
        $cc = $this->parseAddressList($header->cc ?? []);

        // Message-ID
        $messageId = isset($header->message_id) ? trim($header->message_id, '<> ') : '';

        // In-Reply-To (per thread matching)
        $inReplyTo = '';
        $rawHeader = imap_fetchheader($this->connection, $msgNum);
        if (preg_match('/^In-Reply-To:\s*(.+)$/mi', $rawHeader, $m)) {
            $inReplyTo = trim($m[1], '<> ');
        }

        // References
        $references = '';
        if (preg_match('/^References:\s*(.+)$/mi', $rawHeader, $m)) {
            $references = trim($m[1]);
        }

        // Body
        $body = $this->getBody($structure, $msgNum);

        // Attachments
        $attachments = $this->getAttachments($structure, $msgNum);

        // Date
        $date = isset($header->date) ? $header->date : '';

        return [
            'msg_num'      => $msgNum,
            'subject'      => $subject,
            'from'         => $from,
            'to'           => $to,
            'cc'           => $cc,
            'message_id'   => $messageId,
            'in_reply_to'  => $inReplyTo,
            'references'   => $references,
            'body'         => $body,
            'attachments'  => $attachments,
            'date'         => $date,
        ];
    }

    public function markAsRead($msgNum)
    {
        if (!$this->connection) {
            return false;
        }
        return imap_setflag_full($this->connection, (string) $msgNum, '\\Seen');
    }

    public function disconnect()
    {
        if ($this->connection) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }

    // ========================================
    // Parsing helpers
    // ========================================

    private function parseAddressList($addresses)
    {
        $result = [];
        if (!is_array($addresses)) {
            return $result;
        }

        foreach ($addresses as $addr) {
            $email = '';
            if (isset($addr->mailbox) && isset($addr->host)) {
                $email = $addr->mailbox . '@' . $addr->host;
            }

            $name = '';
            if (isset($addr->personal)) {
                $name = $this->decodeMimeStr($addr->personal);
            }

            if ($email) {
                $result[] = [
                    'name'    => $name,
                    'address' => strtolower($email),
                ];
            }
        }

        return $result;
    }

    private function decodeMimeStr($string)
    {
        $elements = imap_mime_header_decode($string);
        $result = '';

        foreach ($elements as $element) {
            $charset = strtoupper($element->charset);
            $text = $element->text;

            if ($charset !== 'DEFAULT' && $charset !== 'UTF-8' && $charset !== 'UTF8') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
                if ($converted !== false) {
                    $text = $converted;
                }
            }

            $result .= $text;
        }

        return $result;
    }

    private function getBody($structure, $msgNum, $partNum = '')
    {
        // Simple (non-multipart)
        if (!isset($structure->parts) || !$structure->parts) {
            $body = imap_fetchbody($this->connection, $msgNum, $partNum ?: '1');
            return $this->decodeBodyPart($body, $structure);
        }

        // Multipart - cerca text/plain poi text/html
        $textBody = '';
        $htmlBody = '';

        foreach ($structure->parts as $index => $part) {
            $currentPartNum = $partNum ? ($partNum . '.' . ($index + 1)) : (string) ($index + 1);

            // Ricorsione per multipart nested
            if ($part->type === 1) { // MULTIPART
                $nested = $this->getBody($part, $msgNum, $currentPartNum);
                if ($nested) {
                    if (!$textBody) $textBody = $nested;
                    else if (!$htmlBody) $htmlBody = $nested;
                }
                continue;
            }

            // Skip allegati
            if ($this->isAttachment($part)) {
                continue;
            }

            $subtype = strtolower($part->subtype ?? '');

            if ($part->type === 0) { // TEXT
                $partBody = imap_fetchbody($this->connection, $msgNum, $currentPartNum);
                $decoded = $this->decodeBodyPart($partBody, $part);

                if ($subtype === 'plain' && !$textBody) {
                    $textBody = $decoded;
                } elseif ($subtype === 'html' && !$htmlBody) {
                    $htmlBody = $decoded;
                }
            }
        }

        // Preferisci plain text, fallback su HTML convertito
        if ($textBody) {
            return $textBody;
        }

        if ($htmlBody) {
            return $this->htmlToText($htmlBody);
        }

        return '';
    }

    private function decodeBodyPart($body, $structure)
    {
        // Transfer encoding
        $encoding = $structure->encoding ?? 0;

        switch ($encoding) {
            case 3: // BASE64
                $body = base64_decode($body);
                break;
            case 4: // QUOTED-PRINTABLE
                $body = quoted_printable_decode($body);
                break;
        }

        // Charset conversion
        $charset = 'UTF-8';
        if (isset($structure->parameters) && is_array($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute) === 'charset') {
                    $charset = strtoupper($param->value);
                    break;
                }
            }
        }

        if ($charset !== 'UTF-8' && $charset !== 'UTF8' && $charset !== 'DEFAULT') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
            if ($converted !== false) {
                $body = $converted;
            }
        }

        return $body;
    }

    private function htmlToText($html)
    {
        // Converti BR e blocchi in newline
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $html);
        $html = preg_replace('/<(p|div|h[1-6])(\s[^>]*)?>/i', "\n", $html);

        // Preserva link
        $html = preg_replace('/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', '$2 ($1)', $html);

        // Rimuovi tutti i tag rimanenti
        $text = strip_tags($html);

        // Decodifica entita HTML
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Pulisci whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        return trim($text);
    }

    private function isAttachment($part)
    {
        // Check disposition
        if (isset($part->disposition) && strtolower($part->disposition) === 'attachment') {
            return true;
        }

        // Check dparameters for filename
        if (isset($part->dparameters) && is_array($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) === 'filename') {
                    return true;
                }
            }
        }

        // Check parameters for name
        if (isset($part->parameters) && is_array($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) === 'name') {
                    // Ma non per text/plain o text/html inline
                    if ($part->type !== 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function getAttachments($structure, $msgNum, $partNum = '')
    {
        $attachments = [];

        if (!isset($structure->parts) || !$structure->parts) {
            return $attachments;
        }

        foreach ($structure->parts as $index => $part) {
            $currentPartNum = $partNum ? ($partNum . '.' . ($index + 1)) : (string) ($index + 1);

            // Ricorsione per multipart
            if ($part->type === 1) {
                $nested = $this->getAttachments($part, $msgNum, $currentPartNum);
                $attachments = array_merge($attachments, $nested);
                continue;
            }

            if (!$this->isAttachment($part)) {
                continue;
            }

            $filename = $this->getAttachmentFilename($part);
            if (!$filename) {
                $filename = 'attachment_' . ($index + 1);
            }

            // Scarica il contenuto
            $content = imap_fetchbody($this->connection, $msgNum, $currentPartNum);

            $encoding = $part->encoding ?? 0;
            if ($encoding === 3) { // BASE64
                $content = base64_decode($content);
            } elseif ($encoding === 4) { // QUOTED-PRINTABLE
                $content = quoted_printable_decode($content);
            }

            if (empty($content)) {
                continue;
            }

            // Salva in file temporaneo
            $tmpDir = get_temp_dir();
            $tmpFile = $tmpDir . 'fs_imap_' . wp_generate_uuid4() . '_' . sanitize_file_name($filename);
            file_put_contents($tmpFile, $content);

            $attachments[] = [
                'filename' => $filename,
                'tmp_path' => $tmpFile,
                'size'     => strlen($content),
                'type'     => $this->getMimeType($part),
            ];
        }

        return $attachments;
    }

    private function getAttachmentFilename($part)
    {
        $filename = '';

        // Check dparameters
        if (isset($part->dparameters) && is_array($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) === 'filename') {
                    $filename = $this->decodeMimeStr($param->value);
                    break;
                }
            }
        }

        // Check parameters
        if (!$filename && isset($part->parameters) && is_array($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) === 'name') {
                    $filename = $this->decodeMimeStr($param->value);
                    break;
                }
            }
        }

        return $filename;
    }

    private function getMimeType($part)
    {
        $types = ['text', 'multipart', 'message', 'application', 'audio', 'image', 'video', 'model', 'other'];
        $type = $types[$part->type] ?? 'application';
        $subtype = strtolower($part->subtype ?? 'octet-stream');
        return $type . '/' . $subtype;
    }

    // ========================================
    // Encryption helpers
    // ========================================

    public static function encryptPassword($password)
    {
        if (empty($password)) {
            return '';
        }
        $key = self::getEncryptionKey();
        return base64_encode(openssl_encrypt($password, 'AES-256-CBC', $key, 0, substr($key, 0, 16)));
    }

    public static function decryptPassword($encrypted)
    {
        if (empty($encrypted)) {
            return '';
        }
        $key = self::getEncryptionKey();
        $decrypted = openssl_decrypt(base64_decode($encrypted), 'AES-256-CBC', $key, 0, substr($key, 0, 16));
        return $decrypted !== false ? $decrypted : '';
    }

    private static function getEncryptionKey()
    {
        // Usa AUTH_KEY di WordPress come base per la chiave di crittografia
        $key = defined('AUTH_KEY') ? AUTH_KEY : 'fs-imap-default-key-change-me';
        return hash('sha256', $key, true);
    }
}
