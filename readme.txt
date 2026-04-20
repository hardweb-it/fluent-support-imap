=== Fluent Support IMAP Fetcher ===
Contributors: hardweb
Tags: fluent-support, imap, email, tickets, helpdesk
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fetch emails via IMAP and create support tickets in Fluent Support, without relying on the remote email piping service.

== Description ==

Fluent Support Pro's built-in email piping depends on an external API server to relay emails. When that server is down or unreachable, email-to-ticket conversion stops working entirely.

**IMAP Fetcher** eliminates this dependency by connecting directly to your mail server via IMAP, polling for new emails on a configurable interval, and converting them into Fluent Support tickets and responses — all without any external service.

= Key Features =

* **Direct IMAP connection** to any mail server (Gmail, Outlook, cPanel/DirectAdmin, custom IMAP)
* **Automatic polling** every N minutes via WP Cron (default: 5 minutes, configurable per mailbox)
* **Smart thread detection** — replies are matched to existing tickets via In-Reply-To headers, ticket ID in subject line (#123), or subject line matching
* **Attachment support** — PDF, images, documents are imported and attached to tickets
* **MIME type fallback** — triple-check via IMAP header, mime_content_type(), and file extension
* **Deduplication** — prevents the same email from creating duplicate tickets
* **CC tracking** — CC addresses are preserved on tickets and responses
* **Customer auto-creation** — new senders are automatically registered as Fluent Support customers, linked to WordPress users when possible
* **Loop prevention** — emails sent from the mailbox's own address are ignored
* **Encrypted credentials** — IMAP passwords are stored encrypted (AES-256-CBC)
* **Verbose logging toggle** — detailed debug logs on demand, clean logs in production
* **Fully translatable** — English default with Italian translation included
* **Works without Fluent Support Pro** — only the free Fluent Support plugin is required

= How It Works =

1. WP Cron triggers every 5 minutes (configurable)
2. For each mailbox with IMAP enabled, the plugin connects to the mail server
3. Unread emails are fetched (max 20 per cycle)
4. Each email is processed: customer is found or created, thread is detected, ticket or response is created, attachments are imported
5. The email is marked as read on the IMAP server
6. Results are logged

Emails remain in your mailbox (marked as read) — you will still receive them in your email client.

== Installation ==

1. Upload the `fluent-support-imap` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Make sure the PHP IMAP extension is enabled on your server
4. Go to Fluent Support → Business Inboxes → select an Email-type inbox
5. Click "IMAP Fetcher" in the left sidebar
6. Enter your IMAP credentials, test the connection, and enable automatic fetching

= Server Requirements =

* PHP 7.4 or higher
* PHP IMAP extension enabled
* Fluent Support (free version) installed and activated

= Enabling PHP IMAP =

* **cPanel:** Select PHP Version → check `imap` → Save
* **DirectAdmin:** Select PHP Version → Extensions → enable `php-imap`
* **Plesk:** PHP Settings → Additional extensions → `imap`
* **CLI / custom:** `sudo apt install php-imap && sudo systemctl restart php-fpm`

== Frequently Asked Questions ==

= Is Fluent Support Pro required? =

No. This plugin works with the free version of Fluent Support. Fluent Support Pro is not required.

= Does this replace the Email Piping feature? =

Yes. IMAP Fetcher is a self-hosted alternative to Fluent Support Pro's email piping. It connects directly to your mail server without depending on any external API.

= What happens to my emails after they are fetched? =

Emails are marked as read on the IMAP server. They are not deleted or moved — you will still see them in your email client.

= Can I configure different intervals for different mailboxes? =

Yes. Each mailbox has its own interval setting. The minimum effective interval depends on how often WP Cron runs (typically every 5 minutes).

= What email servers are supported? =

Any server that supports IMAP with SSL, TLS, or unencrypted connections. This includes Gmail, Outlook/Office 365, Yahoo, cPanel, DirectAdmin, Plesk, Zimbra, and any custom IMAP server.

= How are my IMAP credentials stored? =

Passwords are encrypted using AES-256-CBC with your WordPress AUTH_KEY as the encryption base. They are never stored in plain text.

= What file types are supported for attachments? =

All file types allowed by your Fluent Support configuration are supported. The plugin uses a triple MIME type detection (IMAP header, server-side detection, file extension) to ensure attachments are correctly identified.

= How does thread detection work? =

The plugin matches incoming emails to existing tickets using three methods (in order of priority):
1. In-Reply-To and References email headers
2. Ticket ID in the subject line (e.g., "Re: Issue #42")
3. Exact subject line match for the same customer

= Can I see what the plugin is doing? =

Yes. The IMAP Fetcher panel includes a real-time log viewer. Enable "Verbose logging" for detailed debug information including attachment processing, MIME type detection, and database record details.

= What if WP Cron is not reliable on my server? =

You can set up a real server-side cron job to call `wp-cron.php` at your desired interval. You can also use the "Fetch Now" button for manual fetching at any time.

== Screenshots ==

1. IMAP Fetcher configuration panel integrated in Fluent Support mailbox settings
2. Connection test with email count
3. Fetch results with detailed logging
4. Verbose logging showing attachment processing details

== Changelog ==

= 1.2.0 =
* Initial public release
* Direct IMAP connection with SSL/TLS support
* Automatic polling with configurable interval per mailbox
* Smart thread detection (In-Reply-To, subject #ID, subject match)
* Attachment import with MIME type triple-fallback
* Deduplication, CC tracking, customer auto-creation
* Encrypted credential storage (AES-256-CBC)
* UI injected into Fluent Support mailbox settings page
* Works with or without Fluent Support Pro
* Verbose logging toggle for debugging
* Full i18n support with Italian translation

== Upgrade Notice ==

= 1.2.0 =
Initial release. No upgrade steps required.
