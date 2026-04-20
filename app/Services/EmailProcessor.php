<?php

namespace FSImap\App\Services;

use FluentSupport\App\Models\Attachment;
use FluentSupport\App\Models\Conversation;
use FluentSupport\App\Models\Customer;
use FluentSupport\App\Models\MailBox;
use FluentSupport\App\Models\Meta;
use FluentSupport\App\Models\Ticket;
use FluentSupport\App\Services\Helper;
use FluentSupport\Framework\Support\Arr;

class EmailProcessor
{
    /**
     * Processa un'email parsata e crea ticket o risposta in Fluent Support.
     */
    public static function processEmail(array $email, MailBox $box)
    {
        $fromList = $email['from'] ?? [];
        if (empty($fromList)) {
            return new \WP_Error('no_sender', __('Email without sender', 'fluent-support-imap'));
        }

        $senderEmail = strtolower($fromList[0]['address'] ?? '');
        $senderName = $fromList[0]['name'] ?? '';

        if (empty($senderEmail) || !is_email($senderEmail)) {
            return new \WP_Error('invalid_sender', sprintf(__('Invalid sender email: %s', 'fluent-support-imap'), $senderEmail));
        }

        // Loop prevention
        if ($senderEmail === strtolower($box->email)) {
            return new \WP_Error('self_email', __('Email sent from the same mailbox, ignored', 'fluent-support-imap'));
        }

        $subject = self::cleanSubject($email['subject'] ?? __('(No subject)', 'fluent-support-imap'));
        $body = $email['body'] ?? '';
        $messageId = $email['message_id'] ?? '';

        // Deduplication
        if ($messageId && self::isAlreadyProcessed($messageId)) {
            return new \WP_Error('duplicate', sprintf(__('Email already processed (message_id: %s)', 'fluent-support-imap'), $messageId));
        }

        $customer = self::findOrCreateCustomer($senderEmail, $senderName);
        if (is_wp_error($customer)) {
            return $customer;
        }

        if ($customer->status === 'inactive' || $customer->status === 'blocked') {
            return new \WP_Error('customer_inactive', sprintf(__('Customer blocked/inactive (%1$s): %2$s', 'fluent-support-imap'), $customer->status, $senderEmail));
        }

        // Determina se è un nuovo ticket o una risposta a ticket esistente
        $existingTicket = self::findExistingTicket($email, $customer, $box);

        // CC emails
        $ccEmails = self::extractCcEmails($email, $box);

        if ($existingTicket) {
            $result = self::createResponse($existingTicket, $customer, $body, $messageId, $email, $box, $ccEmails);
        } else {
            $result = self::createTicket($customer, $subject, $body, $messageId, $email, $box, $ccEmails);
        }

        // Segna come processata
        if ($messageId && !is_wp_error($result)) {
            self::markAsProcessed($messageId);
        }

        return $result;
    }

    /**
     * Crea un nuovo ticket.
     */
    private static function createTicket($customer, $subject, $body, $messageId, $email, $box, $ccEmails)
    {
        // Controlla duplicati via content hash
        $contentHash = md5($body);
        $existing = Ticket::where('customer_id', $customer->id)
            ->where('content_hash', $contentHash)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->first();

        if ($existing) {
            return new \WP_Error('duplicate_content', __('Duplicate ticket by content', 'fluent-support-imap'));
        }

        $ticketData = [
            'customer_id' => $customer->id,
            'mailbox_id'  => $box->id,
            'title'       => sanitize_text_field($subject),
            'content'     => wp_kses_post($body),
            'source'      => 'email',
            'priority'    => 'normal',
            'status'      => 'new',
            'message_id'  => $messageId ? sanitize_text_field($messageId) : '',
        ];

        $ticketData = apply_filters('fluent_support/create_ticket_data', $ticketData, $customer);

        do_action('fluent_support/before_ticket_create', $ticketData, $customer);

        $ticket = Ticket::create($ticketData);

        if (!$ticket || !$ticket->id) {
            return new \WP_Error('ticket_creation_error', __('Ticket creation error', 'fluent-support-imap'));
        }

        // Salva CC
        if (!empty($ccEmails)) {
            $ticket->updateMeta('cc_email', $ccEmails);
            $ticket->updateMeta('all_cc_email', $ccEmails);
        }

        // Gestisci allegati
        self::processAttachments($email['attachments'] ?? [], $ticket, $customer, null, $box->id);

        do_action('fluent_support/ticket_created', $ticket, $customer);

        ImapLogger::log(
            sprintf(__('New ticket #%1$d created by %2$s: %3$s', 'fluent-support-imap'), $ticket->id, $customer->email, $subject),
            'info',
            $box->id
        );

        return [
            'type'      => 'new_ticket',
            'ticket_id' => $ticket->id,
        ];
    }

    /**
     * Crea una risposta a un ticket esistente.
     */
    private static function createResponse($ticket, $customer, $body, $messageId, $email, $box, $ccEmails)
    {
        // Controlla duplicati risposta
        $contentHash = md5($body);
        $existing = Conversation::where('ticket_id', $ticket->id)
            ->where('content_hash', $contentHash)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->first();

        if ($existing) {
            return new \WP_Error('duplicate_response', __('Duplicate response', 'fluent-support-imap'));
        }

        $responseData = [
            'ticket_id'         => $ticket->id,
            'person_id'         => $customer->id,
            'conversation_type' => 'response',
            'content'           => wp_kses_post($body),
            'source'            => 'email',
            'message_id'        => $messageId ? sanitize_text_field($messageId) : '',
        ];

        $response = Conversation::create($responseData);

        if (!$response || !$response->id) {
            return new \WP_Error('response_creation_error', __('Response creation error', 'fluent-support-imap'));
        }

        // Aggiorna ticket
        $updateData = [
            'status'                 => 'active',
            'last_customer_response' => current_time('mysql'),
            'waiting_since'          => current_time('mysql'),
            'response_count'         => $ticket->response_count + 1,
        ];
        Ticket::where('id', $ticket->id)->update($updateData);

        // Aggiorna CC
        if (!empty($ccEmails)) {
            $existingCc = $ticket->getMeta('all_cc_email', []);
            if (is_array($existingCc)) {
                $ccEmails = array_unique(array_merge($existingCc, $ccEmails));
            }
            $ticket->updateMeta('all_cc_email', $ccEmails);
        }

        // Gestisci allegati
        self::processAttachments($email['attachments'] ?? [], $ticket, $customer, $response, $box->id);

        do_action('fluent_support/response_added_by_customer', $response, $ticket, $customer);

        ImapLogger::log(
            sprintf(__('Response added to ticket #%1$d by %2$s', 'fluent-support-imap'), $ticket->id, $customer->email),
            'info',
            $box->id
        );

        return [
            'type'        => 'new_response',
            'ticket_id'   => $ticket->id,
            'response_id' => $response->id,
        ];
    }

    /**
     * Cerca un ticket esistente a cui questa email potrebbe essere una risposta.
     */
    private static function findExistingTicket($email, $customer, $box)
    {
        // 1. Match per In-Reply-To / References (thread email)
        $inReplyTo = $email['in_reply_to'] ?? '';
        $references = $email['references'] ?? '';

        if ($inReplyTo) {
            $ticket = Ticket::where('message_id', $inReplyTo)
                ->where('mailbox_id', $box->id)
                ->first();
            if ($ticket) return $ticket;

            // Cerca anche nelle risposte
            $conversation = Conversation::where('message_id', $inReplyTo)->first();
            if ($conversation) {
                $ticket = Ticket::find($conversation->ticket_id);
                if ($ticket) return $ticket;
            }
        }

        // 2. Match per ticket ID nel subject (#123)
        $subject = $email['subject'] ?? '';
        if (preg_match('/#(\d+)/', $subject, $matches)) {
            $ticketId = intval($matches[1]);
            $ticket = Ticket::where('id', $ticketId)
                ->where('mailbox_id', $box->id)
                ->first();
            if ($ticket) return $ticket;
        }

        // 3. Match per subject (solo per customer esistente con ticket aperti)
        $cleanSubject = self::cleanSubject($subject);
        if (strlen($cleanSubject) > 5) {
            $ticket = Ticket::where('customer_id', $customer->id)
                ->where('mailbox_id', $box->id)
                ->where('title', $cleanSubject)
                ->whereIn('status', ['new', 'active'])
                ->orderBy('id', 'DESC')
                ->first();
            if ($ticket) return $ticket;
        }

        return null;
    }

    /**
     * Trova o crea un customer dal mittente dell'email.
     */
    private static function findOrCreateCustomer($email, $name)
    {
        $nameParts = self::splitName($name);

        $data = [
            'email'      => $email,
            'first_name' => $nameParts['first_name'],
            'last_name'  => $nameParts['last_name'],
        ];

        // Cerca utente WordPress esistente
        $wpUser = get_user_by('email', $email);
        if ($wpUser) {
            $data['user_id'] = $wpUser->ID;
            if (empty($data['first_name'])) {
                $data['first_name'] = $wpUser->first_name;
            }
            if (empty($data['last_name'])) {
                $data['last_name'] = $wpUser->last_name;
            }
        }

        return Customer::maybeCreateCustomer($data);
    }

    /**
     * Processa e salva gli allegati.
     */
    private static function processAttachments($attachments, $ticket, $customer, $conversation = null, $boxId = null)
    {
        if (empty($attachments)) {
            return;
        }

        $saved = 0;
        $uploadDir = wp_upload_dir();
        $baseDir = $uploadDir['basedir'] . '/fluent-support/email_attachments';
        $baseUrl = $uploadDir['baseurl'] . '/fluent-support/email_attachments';

        if (!file_exists($baseDir)) {
            wp_mkdir_p($baseDir);
        }

        $htaccessFile = $baseDir . '/.htaccess';
        if (!file_exists($htaccessFile)) {
            file_put_contents($htaccessFile, "Options -Indexes\n");
        }

        $acceptedMimes = Helper::ticketAcceptedFileMiles();

        foreach ($attachments as $attachment) {
            $tmpPath = $attachment['tmp_path'] ?? '';
            $filename = sanitize_file_name($attachment['filename'] ?? 'attachment');
            $mimeType = $attachment['type'] ?? '';

            ImapLogger::log(sprintf('  Attachment: %s (type: %s, tmp exists: %s)', $filename, $mimeType, file_exists($tmpPath) ? 'yes' : 'NO'), 'debug', $boxId);

            if (!$tmpPath || !file_exists($tmpPath)) {
                ImapLogger::log(sprintf('  SKIPPED: temp file missing for %s', $filename), 'warning', $boxId);
                continue;
            }

            // Verifica tipo file accettato - fallback su estensione se MIME type non corrisponde
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!empty($acceptedMimes) && !in_array($mimeType, $acceptedMimes)) {
                $detectedMime = '';
                if (function_exists('mime_content_type')) {
                    $detectedMime = mime_content_type($tmpPath);
                }
                if ($detectedMime && in_array($detectedMime, $acceptedMimes)) {
                    $mimeType = $detectedMime;
                    ImapLogger::log(sprintf('  MIME corrected via mime_content_type: %s', $mimeType), 'debug', $boxId);
                } else {
                    $mimeByExt = wp_check_filetype($filename);
                    if (!empty($mimeByExt['type']) && in_array($mimeByExt['type'], $acceptedMimes)) {
                        $mimeType = $mimeByExt['type'];
                        ImapLogger::log(sprintf('  MIME corrected via extension: %s', $mimeType), 'debug', $boxId);
                    } else {
                        ImapLogger::log(sprintf('  REJECTED: MIME %s not accepted for %s', $mimeType, $filename), 'warning', $boxId);
                        @unlink($tmpPath);
                        continue;
                    }
                }
            }

            $uniqueName = wp_generate_uuid4() . '_' . $filename;
            $destPath = $baseDir . '/' . $uniqueName;
            $destUrl = $baseUrl . '/' . $uniqueName;

            if (!@rename($tmpPath, $destPath)) {
                @copy($tmpPath, $destPath);
                @unlink($tmpPath);
            }

            if (!file_exists($destPath)) {
                ImapLogger::log(sprintf('  FAILED: could not save %s', $filename), 'error', $boxId);
                continue;
            }

            $attachData = [
                'ticket_id'  => $ticket->id,
                'person_id'  => $customer->id,
                'file_type'  => $mimeType,
                'file_path'  => $destPath,
                'full_url'   => $destUrl,
                'title'      => $filename,
                'driver'     => 'local',
                'file_size'  => filesize($destPath),
                'status'     => 'active',
            ];

            if ($conversation) {
                $attachData['conversation_id'] = $conversation->id;
            }

            $att = Attachment::create($attachData);

            ImapLogger::log(sprintf('  DB record: id=%s, ticket_id=%s, conv_id=%s, path=%s',
                $att->id ?? 'FAIL', $att->ticket_id ?? 'N/A', $att->conversation_id ?? 'NULL', $destPath
            ), 'debug', $boxId);

            $saved++;
        }

        ImapLogger::log(
            sprintf(__('Ticket #%1$d: %2$d/%3$d attachments saved', 'fluent-support-imap'), $ticket->id, $saved, count($attachments)),
            'info', $boxId
        );
    }

    /**
     * Estrai email CC escludendo la casella stessa.
     */
    private static function extractCcEmails($email, $box)
    {
        $ccList = $email['cc'] ?? [];
        $toList = $email['to'] ?? [];
        $allAddresses = array_merge($ccList, $toList);

        $ccEmails = [];
        $excludeEmails = [strtolower($box->email)];
        if ($box->mapped_email) {
            $excludeEmails[] = strtolower($box->mapped_email);
        }

        foreach ($allAddresses as $addr) {
            $address = strtolower($addr['address'] ?? '');
            if ($address && is_email($address) && !in_array($address, $excludeEmails)) {
                $ccEmails[] = $address;
            }
        }

        return array_unique($ccEmails);
    }

    /**
     * Pulisce il subject rimuovendo prefissi Re:, Fwd:, ecc.
     */
    private static function cleanSubject($subject)
    {
        return trim(preg_replace('/^(Re:\s*|RE:\s*|Fwd:\s*|FWD:\s*|Fw:\s*|AW:\s*|Aw:\s*|I:\s*|R:\s*)+/i', '', $subject));
    }

    /**
     * Divide un nome completo in nome e cognome.
     */
    private static function splitName($fullName)
    {
        $fullName = trim($fullName);
        if (empty($fullName)) {
            return ['first_name' => '', 'last_name' => ''];
        }

        $parts = explode(' ', $fullName, 2);
        return [
            'first_name' => $parts[0] ?? '',
            'last_name'  => $parts[1] ?? '',
        ];
    }

    /**
     * Controlla se un message_id è già stato processato.
     */
    private static function isAlreadyProcessed($messageId)
    {
        $processed = Helper::getOption('_fs_imap_processed_ids', []);
        if (!is_array($processed)) {
            $processed = [];
        }

        return in_array($messageId, $processed);
    }

    /**
     * Segna un message_id come processato.
     */
    private static function markAsProcessed($messageId)
    {
        $processed = Helper::getOption('_fs_imap_processed_ids', []);
        if (!is_array($processed)) {
            $processed = [];
        }

        $processed[] = $messageId;

        // Mantieni solo gli ultimi 500
        if (count($processed) > 500) {
            $processed = array_slice($processed, -500);
        }

        Helper::updateOption('_fs_imap_processed_ids', $processed);
    }

    /**
     * Helper per aggiornare meta del ticket (compatibile con free).
     */
    private static function updateTicketMeta($ticket, $key, $value)
    {
        if (method_exists($ticket, 'updateMeta')) {
            return $ticket->updateMeta($key, $value);
        }

        // Fallback manuale
        $existing = Meta::where('object_type', 'ticket_meta')
            ->where('object_id', $ticket->id)
            ->where('key', $key)
            ->first();

        if ($existing) {
            $existing->value = maybe_serialize($value);
            $existing->save();
        } else {
            Meta::create([
                'object_type' => 'ticket_meta',
                'object_id'   => $ticket->id,
                'key'         => $key,
                'value'       => maybe_serialize($value),
            ]);
        }
    }
}
