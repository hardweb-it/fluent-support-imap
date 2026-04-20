<?php

namespace FSImap\App\Services;

use FluentSupport\App\Services\Helper;

class ImapLogger
{
    private static $maxLogs = 200;

    public static function log($message, $level = 'info', $boxId = null)
    {
        // Skip debug messages unless verbose logging is enabled
        if ($level === 'debug' && !self::isVerbose()) {
            return;
        }

        $entry = [
            'time'    => current_time('mysql'),
            'level'   => $level,
            'box_id'  => $boxId,
            'message' => $message,
        ];

        $logs = self::getLogs();
        array_unshift($logs, $entry);
        $logs = array_slice($logs, 0, self::$maxLogs);

        Helper::updateOption('_fs_imap_logs', $logs);

        if ($level === 'error') {
            error_log('[FS IMAP] ' . $message);
        }
    }

    public static function getLogs($limit = 50, $boxId = null)
    {
        $logs = Helper::getOption('_fs_imap_logs', []);

        if (!is_array($logs)) {
            return [];
        }

        if ($boxId) {
            $logs = array_filter($logs, function ($log) use ($boxId) {
                return $log['box_id'] == $boxId;
            });
            $logs = array_values($logs);
        }

        return array_slice($logs, 0, $limit);
    }

    public static function clearLogs()
    {
        Helper::updateOption('_fs_imap_logs', []);
    }

    public static function isVerbose()
    {
        return Helper::getOption('_fs_imap_verbose_logging', 'no') === 'yes';
    }

    public static function setVerbose($enabled)
    {
        Helper::updateOption('_fs_imap_verbose_logging', $enabled ? 'yes' : 'no');
    }
}
