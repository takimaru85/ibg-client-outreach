# Compliance checklist

This is not legal advice. It lists what the plugin enforces, what it records, and what remains your responsibility. Which laws apply depends on where you and your recipients are (GDPR and PECR in the EU/UK, CAN-SPAM in the US, CASL in Canada, the Spam Act in Australia, …).

## What the plugin enforces

| Requirement | How |
|---|---|
| Functional unsubscribe in every marketing email | Footer link added automatically; `List-Unsubscribe` + one-click headers |
| Unsubscribe honoured promptly | Immediate; re-checked at send time; suppression survives re-import and deletion |
| Sender identification | Business name, address and the "why you are receiving this" statement in the footer |
| No consent by default | Imports create *Pending* contacts; campaigns default to *Subscribed only* |
| No pre-ticked consent | Importing as Subscribed requires an explicit confirmation that opt-in records exist |
| Do Not Contact | Cannot be lifted by the recipient or an import; admin confirmation required |
| Data minimisation | No email bodies, IPs or user agents stored; configurable retention |
| Access / erasure | WordPress Privacy API exporter and eraser; per-contact erase |

## What the plugin records for you

* `source` — where each contact came from (import file default, form, manual)
* `consent_basis` — the lawful basis you selected
* `consent_at` — when Subscribed was set (or the date you entered)
* `unsubscribed_at`, suppression `reason`, `source` (link / one-click / admin / import / system) and originating campaign
* An activity log per contact (status changes, resubscribes, imports)

## Before your first campaign

- [ ] Postal address entered (Settings → Compliance)
- [ ] From email on your own domain with SPF/DKIM/DMARC
- [ ] Privacy policy published and linked (Settings → Compliance); use the suggested text under Settings → Privacy → Policy Guide
- [ ] Each imported list has a recorded source and lawful basis you could explain
- [ ] Campaign scope reviewed: *Subscribed only* unless you have a documented basis for Pending contacts (B2B legitimate interest / existing customer relationship typically; **not** consumers under PECR/GDPR without consent)
- [ ] Test email received and footer checked
- [ ] Complaints mailbox (Reply-To) monitored

## Ongoing

- [ ] Review the Suppressions screen and bounce handling monthly
- [ ] Respond to access/erasure requests via Tools → Export / Erase Personal Data
- [ ] Keep retention settings proportionate (default: logs 90 days, queue 30 days)
- [ ] If you enable open/click tracking, disclose it in the privacy policy and consider whether a consent basis is needed in your jurisdiction

## Things the plugin will not do

* Send to Unsubscribed, Do Not Contact, bounced or invalid addresses — ever.
* Treat a CSV column as consent.
* Lift a suppression from an import or the API.
* Bypass provider limits or laws. Throttling is a courtesy to your host, not a way around policy.
