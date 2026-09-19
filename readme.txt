=== IBG Client Outreach ===
Contributors: ibgolden
Tags: crm, contacts, email, outreach, campaigns
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight CRM and permission-aware email outreach system for freelance WordPress developers.

== Description ==

IBG Client Outreach combines a contact manager, list segmentation and a queued email campaign system designed for a freelance WordPress developer who needs to stay in touch with legitimate business contacts.

**Privacy by design**

* Imported contacts are never treated as subscribed automatically.
* Every contact records its source and consent basis.
* Unsubscribe and do-not-contact statuses are enforced on import and at send time.
* Open and click tracking are off by default.
* No email bodies are stored in logs; retention is configurable.

**You are responsible** for having a lawful basis to email each contact and for complying with the laws that apply to you (for example GDPR/PECR, CAN-SPAM, CASL) and your email provider's acceptable-use policy. This plugin provides tooling; it does not provide consent.

== Installation ==

1. Upload the `ibg-client-outreach` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Go to IBG Outreach → Settings and complete the General, Email and Compliance tabs.

== Frequently Asked Questions ==

= Where is data stored? =

In dedicated database tables prefixed `{prefix}ibg_`. Nothing is stored as posts or post meta.

= Is data removed on uninstall? =

Only if "Delete data on uninstall" is enabled under Settings → Privacy & Data. Otherwise tables are preserved.

== Changelog ==

= 0.1.0 =
* Phase 1: plugin foundation, database schema, capabilities, settings, provider abstraction.
