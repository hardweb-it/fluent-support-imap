<?php

namespace FSImap\App\Http\Controllers;

use FluentSupport\App\Models\MailBox;
use FluentSupport\Framework\Http\Controller;
use FSImap\App\Hooks\CronHandler;
use FSImap\App\Services\ImapConnector;
use FSImap\App\Services\ImapLogger;

class ImapSettingsController extends Controller
{
    public function getConfig($box_id)
    {
        $box = MailBox::findOrFail($box_id);
        $config = $box->getMeta('_imap_config');

        if ($config && is_string($config)) {
            $config = maybe_unserialize($config);
        }

        if (!$config || !is_array($config)) {
            $config = self::getDefaultConfig();
        }

        // Non esporre la password al frontend
        $config['password'] = !empty($config['password']) ? '********' : '';
        $config['imap_available'] = extension_loaded('imap');

        return ['config' => $config];
    }

    public function saveConfig($box_id)
    {
        $box = MailBox::findOrFail($box_id);

        $data = $this->request->all();
        $config = $box->getMeta('_imap_config');
        if ($config && is_string($config)) {
            $config = maybe_unserialize($config);
        }
        if (!is_array($config)) {
            $config = self::getDefaultConfig();
        }

        $interval = intval($data['interval'] ?? $config['interval'] ?? 5);
        if ($interval < 1) $interval = 1;
        if ($interval > 1440) $interval = 1440;

        $newConfig = [
            'host'       => sanitize_text_field($data['host'] ?? $config['host'] ?? ''),
            'port'       => intval($data['port'] ?? $config['port'] ?? 993),
            'username'   => sanitize_text_field($data['username'] ?? $config['username'] ?? ''),
            'encryption' => sanitize_text_field($data['encryption'] ?? $config['encryption'] ?? 'ssl'),
            'enabled'    => sanitize_text_field($data['enabled'] ?? $config['enabled'] ?? 'no'),
            'interval'   => $interval,
        ];

        // Password: aggiorna solo se non è il placeholder
        $password = $data['password'] ?? '';
        if ($password && $password !== '********') {
            $newConfig['password'] = ImapConnector::encryptPassword($password);
        } else {
            $newConfig['password'] = $config['password'] ?? '';
        }

        $box->saveMeta('_imap_config', $newConfig);

        // Se abilitato, assicurati che il cron sia schedulato
        if ($newConfig['enabled'] === 'yes') {
            if (!wp_next_scheduled('fluent_support_imap_fetch')) {
                wp_schedule_event(time(), 'every_five_minutes', 'fluent_support_imap_fetch');
            }
        }

        ImapLogger::log(sprintf(__('IMAP config updated for MailBox #%d', 'fluent-support-imap'), $box->id), 'info', $box->id);

        return [
            'message' => __('IMAP configuration saved successfully', 'fluent-support-imap'),
        ];
    }

    public function testConnection($box_id)
    {
        $box = MailBox::findOrFail($box_id);
        $config = $box->getMeta('_imap_config');

        if ($config && is_string($config)) {
            $config = maybe_unserialize($config);
        }

        if (!$config || empty($config['host'])) {
            return $this->sendError([
                'message' => __('Please save the IMAP configuration first', 'fluent-support-imap')
            ]);
        }

        $connector = new ImapConnector();
        $result = $connector->testConnection($config);

        if (is_wp_error($result)) {
            ImapLogger::log(sprintf(__('Connection test failed for MailBox #%1$d: %2$s', 'fluent-support-imap'), $box->id, $result->get_error_message()), 'error', $box->id);
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        ImapLogger::log(sprintf(__('Connection test successful for MailBox #%d', 'fluent-support-imap'), $box->id), 'info', $box->id);
        return $result;
    }

    public function fetchNow($box_id)
    {
        $box = MailBox::findOrFail($box_id);
        $config = $box->getMeta('_imap_config');

        if ($config && is_string($config)) {
            $config = maybe_unserialize($config);
        }

        if (!$config || empty($config['host'])) {
            return $this->sendError([
                'message' => __('Please save the IMAP configuration first', 'fluent-support-imap')
            ]);
        }

        $handler = new CronHandler();
        $result = $handler->fetchMailbox($box, $config);

        return [
            'message' => __('Fetch completed', 'fluent-support-imap'),
            'result'  => $result,
        ];
    }

    public function getLogs()
    {
        $boxId = $this->request->get('box_id');
        $limit = intval($this->request->get('limit', 50));

        $logs = ImapLogger::getLogs($limit, $boxId);

        return ['logs' => $logs];
    }

    public function clearLogs()
    {
        ImapLogger::clearLogs();
        return ['message' => __('Logs cleared', 'fluent-support-imap')];
    }

    public function getVerbose()
    {
        return ['verbose' => ImapLogger::isVerbose()];
    }

    public function setVerbose()
    {
        $enabled = $this->request->get('verbose') === 'yes';
        ImapLogger::setVerbose($enabled);
        return [
            'verbose' => $enabled,
            'message' => $enabled
                ? __('Verbose logging enabled', 'fluent-support-imap')
                : __('Verbose logging disabled', 'fluent-support-imap'),
        ];
    }

    private static function getDefaultConfig()
    {
        return [
            'host'       => '',
            'port'       => 993,
            'username'   => '',
            'password'   => '',
            'encryption' => 'ssl',
            'enabled'    => 'no',
            'interval'   => 5,
        ];
    }
}
