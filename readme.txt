=== IBG Client Outreach ===
Contributors: ibgolden
Tags: crm, contacts, email, outreach, campaigns
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight CRM and permission-aware email outreach system for freelance WordPress developers and small agencies.

== Description ==

IBG Client Outreach combines a contact manager, list segmentation, reusable templates and a queued, throttled email campaign engine — built for someone who wants to stay in touch with legitimate business contacts without emailing them one by one, and without pretending the hosting server is a bulk-mail platform.

**Contacts & lists**

* Custom tables (not post meta) with real indexes.
* Contact status (Lead / Contact / Customer / Inactive) separate from marketing status (Pending / Subscribed / Unsubscribed / Do Not Contact).
* Source and lawful-basis recorded per contact, with a full activity log.
* Fixed lists and dynamic segments (saved filters) usable as campaign audiences.
* CSV import wizard: drag-and-drop, delimiter/BOM detection, column mapping, pre-import analysis (valid / invalid / duplicates / previously unsubscribed), duplicate strategy, batched import with progress.
* CSV export of any filtered view (formula-injection safe).

**Campaigns**

* Templates with merge tags (`{{first_name|there}}`, `{{company}}`, `{{website_domain}}` …), HTML editor, generated plain-text part, live preview, test send.
* Pre-flight panel: Recipients / Excluded (with reasons) / Estimated sends, plus compliance checks (postal address, From domain, template, provider).
* Consent scope per campaign: Subscribed only, or Subscribed + Pending (explicit choice with a warning).
* Draft → Scheduled → Processing → Paused / Completed / Cancelled, with content snapshotted at start.

**Delivery**

* Email queue processed by WP-Cron in small configurable batches with retries, backoff, atomic row claiming (no duplicate sends), stale-lock recovery and a time budget.
* Eligibility re-checked at send time, so an unsubscribe after queueing is honoured.
* Providers: WordPress `wp_mail()` and a built-in SMTP transport (encrypted password or wp-config constant). Provider interface + webhook pipeline for API providers (bounces, complaints).
* Every email carries sender identification, your postal address, the reason for contact, an unsubscribe link and RFC 8058 `List-Unsubscribe` headers.

**Privacy by design**

* Imported contacts are never treated as subscribed automatically.
* Unsubscribe page with POST confirmation (safe against link scanners), one-click unsubscribe support, resubscribe only for plain unsubscribes, never Do Not Contact.
* Suppression list keyed by hash, retained through contact deletion and GDPR erasure so an opt-out is honoured forever.
* Open/click tracking off by default; when enabled, signed URLs, no IP or user-agent stored.
* WordPress Privacy API exporter and eraser, suggested policy text, configurable retention for logs, queue and engagement events.
* No email bodies are stored.

**You are responsible** for having a lawful basis to email each contact and for complying with the laws that apply to you (for example GDPR/PECR, CAN-SPAM, CASL) and your email provider's acceptable-use policy. The plugin provides tooling and guard-rails; it does not provide consent.

== Installation ==

1. Upload the `ibg-client-outreach` folder to `/wp-content/plugins/` and activate it.
2. Go to **IBG Outreach → Settings** and complete General (sender identity), Email (provider, batch size) and Compliance (postal address, footer).
3. Configure SPF, DKIM and DMARC for your sending domain.
4. For reliable sending, add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php` and run `wp-cron.php` from a system cron every minute.
5. Import or add contacts, create a list, create a template, create a campaign, send yourself a test, then schedule or start it.

See `docs/setup.md` in the plugin folder for the full guide.

== Frequently Asked Questions ==

= Where is data stored? =

In ten dedicated tables prefixed `{prefix}ibg_`. Settings live in one option. Nothing uses posts or post meta.

= Can an imported contact be emailed straight away? =

Only if you choose the "Subscribed + Pending" scope on a campaign and you have recorded a lawful basis for those contacts. Imports default to Pending; the default campaign scope is Subscribed only.

= What happens when someone unsubscribes and I import them again? =

They stay unsubscribed. The suppression list is checked on every import, every manual edit and again at send time.

= Does it send from my server? =

By default through `wp_mail()`. Switch to the SMTP provider to send through your host's SMTP or the SMTP endpoint of Brevo, Mailgun, SendGrid, Amazon SES, etc.

= Is data removed on uninstall? =

Only if "Delete data on uninstall" is enabled under Settings → Privacy & Data. Otherwise all tables are preserved for reinstallation.

= Is there an API? =

Yes: `/wp-json/ibg/v1/contacts|templates|campaigns|stats`, authenticated with WordPress cookies or Application Passwords and gated by the plugin capabilities. See `docs/developer.md`.

== Changelog ==

= 1.0.0 =
* Initial release: contacts, CSV import, lists & segments, templates with merge tags, campaigns, email queue with WP-Cron, unsubscribe & suppression, wp_mail and SMTP providers, webhook pipeline, dashboard & analytics, opt-in tracking, REST API, Privacy API integration.
