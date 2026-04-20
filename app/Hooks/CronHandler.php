<?php

namespace FSImap\App\Hooks;

use FluentSupport\App\Models\MailBox;
use FSImap\App\Services\EmailProcessor;
use FSImap\App\Services\ImapConnector;
use FSImap\App\Services\ImapLogger;

class CronHandler
{
    public function register()
    {
        // Registra intervallo cron custom
        add_filter('cron_schedules', [$this, 'addCronInterval']);

        // Hook per il fetch
        add_action('fluent_support_imap_fetch', [$this, 'fetchAllMailboxes']);
    }

    public function addCronInterval($schedules)
    {
        $schedules['every_five_minutes'] = [
            'interval' => 300,
            'display'  => __('Every 5 minutes', 'fluent-support-imap'),
        ];
        return $schedules;
    }

    public function fetchAllMailboxes()
    {
        // Lock per prevenire esecuzioni concorrenti
        $lockKey = 'fs_imap_fetch_lock';
        if (get_transient($lockKey)) {
            ImapLogger::log(__('Fetch already running, skipping.', 'fluent-support-imap'), 'warning');
            return;
        }
        set_transient($lockKey, true, 300); // Lock 5 minuti max

        try {
            $this->doFetch();
        } finally {
            delete_transient($lockKey);
        }
    }

    private function doFetch()
    {
        $mailboxes = MailBox::where('box_type', 'email')->get();

        if (!$mailboxes || $mailboxes->isEmpty()) {
            return;
        }

        foreach ($mailboxes as $box) {
            $config = $this->getImapConfig($box);

            if (!$config || empty($config['enabled']) || $config['enabled'] !== 'yes') {
                continue;
            }

            // Rispetta l'interval per-mailbox: skip se non e' ancora trascorso
            $interval = intval($config['interval'] ?? 5);
            if ($interval < 1) $interval = 1;
            $lastFetch = (int) $box->getMeta('_imap_last_fetch_at');
            if ($lastFetch && (time() - $lastFetch) < ($interval * 60)) {
                continue;
            }

            $box->saveMeta('_imap_last_fetch_at', time());
            $this->fetchMailbox($box, $config);
        }
    }

    public function fetchMailbox(MailBox $box, $config = null)
    {
        if (!$config) {
            $config = $this->getImapConfig($box);
        }

        if (!$config || empty($config['host'])) {
            ImapLogger::log(sprintf(__('IMAP config missing for MailBox #%d', 'fluent-support-imap'), $box->id), 'error', $box->id);
            return ['error' => __('IMAP config missing', 'fluent-support-imap')];
        }

        $connector = new ImapConnector();
        $connection = $connector->connect($config);

        if (is_wp_error($connection)) {
            ImapLogger::log(
                sprintf(__('IMAP connection error for MailBox #%1$d: %2$s', 'fluent-support-imap'), $box->id, $connection->get_error_message()),
                'error',
                $box->id
            );
            return ['error' => $connection->get_error_message()];
        }

        ImapLogger::log(sprintf(__('IMAP connection successful for MailBox #%d', 'fluent-support-imap'), $box->id), 'info', $box->id);

        $emails = $connector->fetchUnreadEmails(20);

        if (is_wp_error($emails)) {
            ImapLogger::log(
                sprintf(__('Email fetch error for MailBox #%1$d: %2$s', 'fluent-support-imap'), $box->id, $emails->get_error_message()),
                'error',
                $box->id
            );
            $connector->disconnect();
            return ['error' => $emails->get_error_message()];
        }

        $processed = 0;
        $errors = 0;
        $skipped = 0;

        foreach ($emails as $email) {
            $result = EmailProcessor::processEmail($email, $box);

            if (is_wp_error($result)) {
                $errorCode = $result->get_error_code();
                if (in_array($errorCode, ['duplicate', 'duplicate_content', 'duplicate_response', 'self_email'])) {
                    $skipped++;
                    // Segna comunque come letta per non riprocessarla
                    $connector->markAsRead($email['msg_num']);
                } else {
                    $errors++;
                    ImapLogger::log(
                        sprintf(__('Email processing error: %s', 'fluent-support-imap'), $result->get_error_message()),
                        'error',
                        $box->id
                    );
                }
                continue;
            }

            // Segna come letta
            $connector->markAsRead($email['msg_num']);
            $processed++;
        }

        $connector->disconnect();

        // Pulisci file temporanei allegati rimasti
        self::cleanTempFiles();

        $summary = sprintf(
            /* translators: 1: mailbox id, 2: total emails found, 3: processed, 4: skipped, 5: errors */
            __('MailBox #%1$d: %2$d emails found, %3$d processed, %4$d skipped, %5$d errors', 'fluent-support-imap'),
            $box->id, count($emails), $processed, $skipped, $errors
        );
        ImapLogger::log($summary, 'info', $box->id);

        return [
            'total'     => count($emails),
            'processed' => $processed,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ];
    }

    private function getImapConfig(MailBox $box)
    {
        $meta = $box->getMeta('_imap_config');
        if ($meta && is_string($meta)) {
            $meta = maybe_unserialize($meta);
        }
        return $meta;
    }

    private static function cleanTempFiles()
    {
        $tmpDir = get_temp_dir();
        $files = glob($tmpDir . 'fs_imap_*');
        $now = time();

        foreach ($files as $file) {
            if ($now - filemtime($file) > 3600) {
                @unlink($file);
            }
        }
    }
}
