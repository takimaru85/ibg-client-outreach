# Setup guide

## 1. Install and activate

Copy the `ibg-client-outreach` folder to `wp-content/plugins/` and activate it. Activation creates the database tables, grants the plugin capabilities to the Administrator role, generates the signing secret and schedules the cron events. The Dashboard → *System status* section confirms all of this.

Updates never need re-activation: schema changes apply automatically on the next admin page load.

## 2. Settings

**General** — Business name, website, default From name / From email / Reply-To.
The From email must be on a domain you control (see `deliverability.md`). Free-mailbox domains (gmail.com, outlook.com) will be rejected or junked by most receivers.

**Email** — Sending provider and throttling.

| Setting | Default | Notes |
|---|---|---|
| Sending provider | WordPress (wp_mail) | Switch to *SMTP server* for anything beyond a handful of emails |
| Batch size | 25 | Emails per queue run |
| Delay between batches | 60 s | WP-Cron runs at most once a minute |
| Maximum retry attempts | 3 | Transient failures only; auth failures are not retried |
| Retry delay | 15 min | Linear backoff: 15, 30, 45 min |

SMTP: host, port, encryption, username, password. The password is stored encrypted; better, define it in `wp-config.php`:

```php
define( 'IBG_OUTREACH_SMTP_PASSWORD', 'your-password' );
```

Use *Send a delivery test* after saving to confirm the connection; results are recorded in Email Logs.

**Compliance** — Postal address (required; campaigns cannot start without it), the "why you are receiving this" statement, unsubscribe text, privacy policy URL, and the footer template. The footer is appended to every marketing email; an unsubscribe link is added automatically if your footer omits it.

**Privacy & Data** — Open/click tracking (off by default; read the explanation before enabling), log/queue retention, delete-on-uninstall.

## 3. Cron

The queue is driven by WP-Cron, which only runs when someone visits the site. For predictable delivery:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

and a real cron entry:

```
* * * * * curl -s "https://example.com/wp-cron.php?doing_wp_cron" > /dev/null
```

or with WP-CLI: `* * * * * wp cron event run --due-now --path=/var/www/example.com`.

The Dashboard and Email Queue screens show the next and last run. *Run queue now* on the Email Queue screen processes a batch immediately.

## 4. Capabilities

| Capability | Grants |
|---|---|
| `ibg_view_outreach` | Menu, dashboard, read-only views, queue & logs |
| `ibg_manage_contacts` | Contacts, lists, suppressions, export, erase |
| `ibg_import_contacts` | CSV import |
| `ibg_manage_campaigns` | Templates and campaign drafts |
| `ibg_send_campaigns` | Schedule / start / pause / cancel, test sends, queue actions |
| `ibg_manage_settings` | Settings, log purge, table repair |

All are granted to Administrators on activation. Grant subsets to other roles with a role plugin or `wp cap add`.

## 5. First campaign

1. **Contacts → Import Contacts**: upload a CSV, map columns, review the analysis, import. Choose the lawful basis and a default source; leave marketing status as *Pending* unless every address explicitly opted in.
2. **Lists / Segments**: create a list (fixed members) or a segment (saved filter such as Industry = Dental, Marketing = Pending).
3. **Email Templates**: create the example template, edit it, preview with a real contact, send yourself a test.
4. **Campaigns → Add New**: template + audience + consent scope. Review *Before sending*: recipients, exclusions, estimated sends and the check list.
5. *Send test*, then *Schedule* or *Start Campaign*. Watch progress on the campaign screen or Email Queue.

## 6. Upgrading and uninstalling

Overwrite the plugin folder with the new version. Data is kept on deactivation. On deletion, data is removed only if *Delete data on uninstall* is enabled.

## 7. Multisite

Network activation installs tables per site; new sites are provisioned automatically. Settings, contacts and capabilities are per site.

## 8. Web server notes

Uploaded CSVs are stored under `wp-content/uploads/ibg-outreach/imports/` behind `.htaccess` (Apache) and `web.config` (IIS) deny rules, with random names, and are deleted after import. On **nginx** add:

```
location ~* /uploads/ibg-outreach/ { deny all; }
```
