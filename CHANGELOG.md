# Changelog

## 1.0.0 — 2026-09-19

Initial release.

- Contacts with CRM and marketing statuses, lawful basis and activity log
- CSV import wizard with analysis, duplicate strategies and batched processing
- Lists and dynamic segments
- Templates with merge tags, preview and test sends
- Campaigns with pre-flight checks, consent scope, scheduling and content snapshot
- Email queue on WP-Cron: atomic batches, retries, stale-lock recovery, send-time eligibility re-check
- wp_mail and SMTP providers; provider + webhook interfaces; delivery event pipeline
- Public unsubscribe (confirmation + RFC 8058 one-click), suppression list with hash retention
- Dashboard, campaign engagement, opt-in signed open/click tracking
- REST API (`ibg/v1`) for contacts, templates, campaigns, stats
- WordPress Privacy API exporter/eraser, CSV exports, retention cleanup
