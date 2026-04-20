<?php
/**
 * Plugin Name: Fluent Support IMAP Fetcher
 * Description: Fetch emails via IMAP and create tickets in Fluent Support, without depending on the remote email piping service.
 * Version: 1.2.0
 * Author: Hardweb
 * Text Domain: fluent-support-imap
 * Domain Path: /languages
 * Requires Plugins: fluent-support
 */

defined('ABSPATH') || exit;

define('FS_IMAP_VERSION', '1.2.0');
define('FS_IMAP_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FS_IMAP_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load translations
add_action('init', function () {
    load_plugin_textdomain('fluent-support-imap', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Listener at top-level (same pattern as Fluent Support Pro)
add_action('fluent_support_loaded', function ($app) {
    require_once FS_IMAP_PLUGIN_PATH . 'app/Services/ImapLogger.php';
    require_once FS_IMAP_PLUGIN_PATH . 'app/Services/ImapConnector.php';
    require_once FS_IMAP_PLUGIN_PATH . 'app/Services/EmailProcessor.php';
    require_once FS_IMAP_PLUGIN_PATH . 'app/Http/Controllers/ImapSettingsController.php';
    require_once FS_IMAP_PLUGIN_PATH . 'app/Hooks/CronHandler.php';

    $router = $app->router;
    require_once FS_IMAP_PLUGIN_PATH . 'app/Http/routes.php';

    $cronHandler = new \FSImap\App\Hooks\CronHandler();
    $cronHandler->register();

    add_action('admin_enqueue_scripts', 'fs_imap_enqueue_assets');
});

// Activation / Deactivation
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('fluent_support_imap_fetch')) {
        wp_schedule_event(time(), 'every_five_minutes', 'fluent_support_imap_fetch');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('fluent_support_imap_fetch');
});

// Dependency check
add_action('admin_notices', function () {
    if (!defined('FLUENT_SUPPORT_VERSION')) {
        echo '<div class="notice notice-error"><p>';
        printf(
            /* translators: %s: plugin name */
            esc_html__('%s requires Fluent Support to be active.', 'fluent-support-imap'),
            '<strong>Fluent Support IMAP Fetcher</strong>'
        );
        echo '</p></div>';
    }
});

function fs_imap_enqueue_assets($hook)
{
    if (strpos($hook, 'fluent-support') === false) {
        return;
    }

    wp_enqueue_script(
        'fs-imap-admin',
        FS_IMAP_PLUGIN_URL . 'assets/admin.js',
        ['jquery'],
        FS_IMAP_VERSION,
        true
    );

    wp_enqueue_style(
        'fs-imap-admin-css',
        FS_IMAP_PLUGIN_URL . 'assets/admin.css',
        [],
        FS_IMAP_VERSION
    );

    // Pre-load all mailbox IMAP configs
    $mailboxes = \FluentSupport\App\Models\MailBox::where('box_type', 'email')->get();
    $configs = [];
    foreach ($mailboxes as $box) {
        $cfg = $box->getMeta('_imap_config');
        if (!is_array($cfg)) {
            $cfg = ['host' => '', 'port' => 993, 'username' => '', 'password' => '', 'encryption' => 'ssl', 'enabled' => 'no', 'interval' => 5];
        }
        $configs[$box->id] = [
            'host'         => $cfg['host'] ?? '',
            'port'         => intval($cfg['port'] ?? 993),
            'username'     => $cfg['username'] ?? '',
            'has_password' => !empty($cfg['password']),
            'encryption'   => $cfg['encryption'] ?? 'ssl',
            'enabled'      => ($cfg['enabled'] ?? 'no') === 'yes',
            'interval'     => intval($cfg['interval'] ?? 5),
        ];
    }

    wp_localize_script('fs-imap-admin', 'fsImapAdmin', [
        'restUrl'       => rest_url('fluent-support/v2/imap-settings'),
        'nonce'         => wp_create_nonce('wp_rest'),
        'imapAvailable' => extension_loaded('imap'),
        'configs'       => $configs,
        'i18n'          => fs_imap_get_i18n_strings(),
    ]);
}

function fs_imap_get_i18n_strings()
{
    return [
        'menu_label'        => __('IMAP Fetcher', 'fluent-support-imap'),
        'panel_title'       => __('IMAP Fetcher', 'fluent-support-imap'),
        'panel_subtitle'    => __('Fetch emails via IMAP every few minutes — alternative to email piping', 'fluent-support-imap'),
        'imap_missing'      => __('<strong>Warning:</strong> The PHP IMAP extension is not installed on the server. Contact your hosting provider to enable it.', 'fluent-support-imap'),

        'label_host'        => __('IMAP Server', 'fluent-support-imap'),
        'label_port'        => __('Port', 'fluent-support-imap'),
        'label_username'    => __('Username', 'fluent-support-imap'),
        'label_password'    => __('Password', 'fluent-support-imap'),
        'label_encryption'  => __('Encryption', 'fluent-support-imap'),
        'label_enabled'     => __('Enabled', 'fluent-support-imap'),
        'label_interval'    => __('Interval (minutes)', 'fluent-support-imap'),

        'placeholder_host'     => __('mail.yourdomain.com', 'fluent-support-imap'),
        'placeholder_username' => __('support@yourdomain.com', 'fluent-support-imap'),

        'opt_ssl'           => __('SSL (recommended)', 'fluent-support-imap'),
        'opt_tls'           => __('TLS', 'fluent-support-imap'),
        'opt_none'          => __('None', 'fluent-support-imap'),

        'toggle_text'       => __('Enable automatic fetch', 'fluent-support-imap'),
        'interval_hint'     => __('⚠️ The actual fetch frequency depends on how often the WP Cron of the site runs. If you set a value below 5, the fetch may still occur every 5 minutes (minimum cron interval).', 'fluent-support-imap'),

        'btn_save'          => __('Save Configuration', 'fluent-support-imap'),
        'btn_test'          => __('Test Connection', 'fluent-support-imap'),
        'btn_fetch'         => __('Fetch Now', 'fluent-support-imap'),
        'btn_refresh'       => __('Refresh', 'fluent-support-imap'),

        'saving'            => __('Saving...', 'fluent-support-imap'),
        'testing'           => __('Testing...', 'fluent-support-imap'),
        'fetching'          => __('Fetching...', 'fluent-support-imap'),

        'config_saved'      => __('Configuration saved!', 'fluent-support-imap'),
        'save_error'        => __('Save error', 'fluent-support-imap'),
        'test_in_progress'  => __('Connection test in progress...', 'fluent-support-imap'),
        'test_success'      => __('Connection successful! Emails in mailbox: %d', 'fluent-support-imap'),
        'test_failed'       => __('Test failed', 'fluent-support-imap'),
        'test_error'        => __('Test error', 'fluent-support-imap'),
        'fetch_in_progress' => __('Email fetch in progress, this may take a few seconds...', 'fluent-support-imap'),
        'fetch_completed'   => __('Fetch completed: %total% emails found, %processed% processed, %skipped% skipped, %errors% errors', 'fluent-support-imap'),
        'fetch_error'       => __('Fetch error', 'fluent-support-imap'),
        'error_prefix'      => __('Error', 'fluent-support-imap'),

        'logs_title'        => __('Recent Logs', 'fluent-support-imap'),
        'loading'           => __('Loading...', 'fluent-support-imap'),
        'no_logs'           => __('No logs available', 'fluent-support-imap'),
        'logs_error'        => __('Error loading logs', 'fluent-support-imap'),
        'col_date'          => __('Date', 'fluent-support-imap'),
        'col_level'         => __('Level', 'fluent-support-imap'),
        'col_message'       => __('Message', 'fluent-support-imap'),

        'verbose_label'     => __('Verbose logging', 'fluent-support-imap'),
        'verbose_hint'      => __('Log detailed debug information (attachments, MIME types, DB records, file paths). Disable in production.', 'fluent-support-imap'),
    ];
}
