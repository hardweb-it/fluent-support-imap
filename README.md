# Fluent Support IMAP Fetcher

A WordPress plugin that fetches emails via IMAP and creates support tickets in [Fluent Support](https://fluentsupport.com/), without relying on the remote email piping service.

## Why?

Fluent Support Pro's built-in email piping depends on an external API server (`apiv2.wpmanageninja.com`) to relay emails. When that server is down or unreachable, email-to-ticket conversion stops working entirely.

**IMAP Fetcher** eliminates this dependency by connecting directly to your mail server via IMAP, polling for new emails on a configurable interval, and converting them into Fluent Support tickets and responses — all without any external service.

## Features

- **Direct IMAP connection** to any mail server (Gmail, Outlook, cPanel/DirectAdmin, custom IMAP)
- **Automatic polling** every N minutes via WP Cron (default: 5 minutes, configurable per mailbox)
- **Smart thread detection** — replies are matched to existing tickets via:
  - `In-Reply-To` / `References` email headers
  - Ticket ID in subject line (`#123`)
  - Subject line matching
- **Attachment support** — PDF, images, documents are imported and attached to tickets
- **MIME type fallback** — triple-check via IMAP header, `mime_content_type()`, and file extension
- **Deduplication** — prevents the same email from creating duplicate tickets
- **CC tracking** — CC addresses are preserved on tickets and responses
- **Customer auto-creation** — new senders are automatically registered as Fluent Support customers, linked to WordPress users when possible
- **Loop prevention** — emails sent from the mailbox's own address are ignored
- **Encrypted credentials** — IMAP passwords are stored encrypted (AES-256-CBC using `AUTH_KEY`)
- **Verbose logging toggle** — detailed debug logs on demand, clean logs in production
- **Fully translatable** — English default with Italian translation included (`.pot` / `.po` / `.mo`)
- **Works without Fluent Support Pro** — only the free Fluent Support plugin is required

## Requirements

- WordPress 6.0+
- PHP 7.4+ with the **IMAP extension** enabled
- [Fluent Support](https://wordpress.org/plugins/fluent-support/) (free version) — the Pro version is **not required**

### Enabling PHP IMAP

| Hosting panel | How to enable |
|---|---|
| **cPanel** | Select PHP Version → check `imap` → Save |
| **DirectAdmin** | Select PHP Version → Extensions → enable `php-imap` |
| **Plesk** | PHP Settings → Additional extensions → `imap` |
| **CLI / custom** | `sudo apt install php-imap && sudo systemctl restart php-fpm` |

## Installation

1. Download or clone this repository
2. Upload the `fluent-support-imap` folder to `/wp-content/plugins/`
3. Activate the plugin in WordPress → Plugins
4. Go to **Fluent Support → Business Inboxes** → select an Email-type inbox
5. Click **IMAP Fetcher** in the left sidebar
6. Enter your IMAP credentials, test the connection, and enable automatic fetching

## Configuration

| Field | Description | Default |
|---|---|---|
| IMAP Server | Your mail server hostname | — |
| Port | IMAP port | `993` |
| Username | Email account username | — |
| Password | Email account password (stored encrypted) | — |
| Encryption | `SSL` (recommended), `TLS`, or `None` | `SSL` |
| Enabled | Toggle automatic fetching on/off | Off |
| Interval | Minutes between each fetch cycle | `5` |

> **Note:** The actual fetch frequency depends on how often WP Cron runs. If you set an interval below 5 minutes, fetches may still occur every 5 minutes (minimum cron interval). For reliable sub-5-minute intervals, consider a real server-side cron job pointing to `wp-cron.php`.

## How It Works

```
WP Cron (every 5 min)
  └─ For each mailbox with IMAP enabled:
       ├─ Check if interval has elapsed since last fetch
       ├─ Connect to IMAP server
       ├─ Fetch unread emails (max 20 per cycle)
       ├─ For each email:
       │    ├─ Find or create customer from sender
       │    ├─ Detect if it's a reply (thread matching) or new ticket
       │    ├─ Create ticket or add response
       │    ├─ Import attachments
       │    └─ Mark email as read on IMAP
       └─ Log results
```

Emails remain in your mailbox (marked as read) — you'll still receive them in your email client.

## Screenshots

The IMAP Fetcher panel integrates directly into the Fluent Support mailbox settings page as a sidebar tab:

- **With Fluent Support Pro:** appears after "Email Piping"
- **Without Pro:** appears after the last settings tab

The panel includes:
- Connection configuration form
- Test Connection / Fetch Now buttons
- Verbose logging toggle
- Real-time log viewer

## Logging

| Mode | What's logged |
|---|---|
| **Normal** (default) | Connection status, tickets/responses created, attachment counts, errors |
| **Verbose** | All of the above + individual attachment details, MIME type detection, DB record IDs, file paths |

Enable verbose logging via the checkbox in the IMAP Fetcher panel → Log section. Disable it in production to keep logs clean.

## File Structure

```
fluent-support-imap/
├── fluent-support-imap.php              # Plugin bootstrap
├── app/
│   ├── Services/
│   │   ├── ImapConnector.php            # IMAP connection, email parsing, attachments
│   │   ├── EmailProcessor.php           # Email → Ticket/Response conversion
│   │   └── ImapLogger.php               # Logging with verbose toggle
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── ImapSettingsController.php   # REST API endpoints
│   │   └── routes.php                   # Route registration
│   └── Hooks/
│       └── CronHandler.php              # WP Cron scheduling & execution
├── assets/
│   ├── admin.js                         # UI panel (DOM injection into FS admin)
│   └── admin.css                        # Panel styling
├── languages/
│   ├── fluent-support-imap.pot          # Translation template (English)
│   ├── fluent-support-imap-it_IT.po     # Italian translation
│   └── fluent-support-imap-it_IT.mo     # Italian translation (compiled)
└── README.md
```

## REST API Endpoints

All endpoints require `manage_options` capability (admin only).

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/fluent-support/v2/imap-settings/{box_id}/config` | Get IMAP config for a mailbox |
| `POST` | `/fluent-support/v2/imap-settings/{box_id}/config` | Save IMAP config |
| `POST` | `/fluent-support/v2/imap-settings/{box_id}/test` | Test IMAP connection |
| `POST` | `/fluent-support/v2/imap-settings/{box_id}/fetch-now` | Trigger immediate fetch |
| `GET` | `/fluent-support/v2/imap-settings/logs` | Get recent logs |
| `POST` | `/fluent-support/v2/imap-settings/logs/clear` | Clear all logs |
| `GET` | `/fluent-support/v2/imap-settings/verbose` | Get verbose logging status |
| `POST` | `/fluent-support/v2/imap-settings/verbose` | Toggle verbose logging |

## Translations

The plugin ships with English as the default language and includes a complete Italian translation.

To add a new language:
1. Copy `languages/fluent-support-imap.pot` to `languages/fluent-support-imap-{locale}.po`
2. Translate all `msgstr` entries
3. Compile to `.mo` using [Poedit](https://poedit.net/) or `msgfmt`

## Troubleshooting

| Problem | Solution |
|---|---|
| IMAP Fetcher tab doesn't appear | Make sure you're on an **Email-type** Business Inbox (not Web), and the plugin is activated |
| "PHP IMAP extension not installed" | Enable `php-imap` in your hosting panel (see Requirements) |
| Connection test fails | Verify server, port, credentials. Try SSL on port 993. Check firewall |
| Emails fetched but no tickets created | Check the logs — look for "customer inactive", "duplicate", or "self email" messages |
| Attachments not showing in tickets | Enable verbose logging and check for MIME rejection or file save errors |
| Fetch not running automatically | Verify WP Cron is working (`wp cron event list`). Consider a real cron job |

## License

This plugin is provided as-is for private use. Not intended for distribution on WordPress.org.

## Credits

Built by [Hardweb](https://hardweb.it) as a self-hosted alternative to Fluent Support Pro's email piping service.
