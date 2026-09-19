# Deliverability guide

Getting mail *accepted* is a different problem from getting it *sent*. This page covers the parts the plugin cannot do for you.

## 1. Send from a domain you control

Use `you@yourdomain.com` as the From email. Gmail, Yahoo, Outlook and most business domains publish DMARC policies that cause mail "from" their domain but sent by your server to be rejected or quarantined.

## 2. DNS records

| Record | Purpose | Example |
|---|---|---|
| SPF (TXT on the domain) | Lists servers allowed to send for the domain | `v=spf1 include:_spf.yourhost.com include:spf.brevo.com -all` |
| DKIM (TXT on a selector) | Cryptographic signature added by the sending server | Provided by your SMTP/API provider |
| DMARC (TXT on `_dmarc`) | Policy + reporting | `v=DMARC1; p=quarantine; rua=mailto:dmarc@yourdomain.com` |

The plugin adds `List-Unsubscribe` and `List-Unsubscribe-Post` headers to every campaign email, which Gmail and Yahoo require from bulk senders, but SPF/DKIM/DMARC alignment is decided by the transport you choose.

* **wp_mail()** — signs nothing. Only viable if your host signs outgoing mail for your domain.
* **SMTP provider** — signing is done by the SMTP service (Brevo, Mailgun, SendGrid, SES, your mail host) once you verify the domain there.

## 3. Warm up and throttle

New domains and IPs should start slow. Defaults (25 per minute) are conservative; for a new domain consider a batch size of 10 with a 120-second delay for the first campaigns, then increase.

## 4. Content

* Use the plain-text part (generated automatically; edit it if the auto version is poor).
* Avoid link shorteners and image-only emails.
* Keep the footer honest: real name, real postal address, real reason for contact.
* If you enable click tracking, links are rewritten through your site — use HTTPS on the site.

## 5. Bounces and complaints

With wp_mail or SMTP, bounces arrive at the From/Return-Path mailbox and are not processed automatically. Options:

* Watch the mailbox and add hard-bouncing addresses on the **Suppressions** screen (reason *Unsubscribed* or *Do Not Contact*), or set the contact's email status to Bounced.
* Use an API provider that supports webhooks (see `developer.md` → Providers); hard bounces and complaints are then applied automatically.

Sending repeatedly to dead addresses is the fastest way to lose domain reputation.

## 6. Testing

Send a test to a mailbox at each major provider (Gmail, Outlook, a business Google Workspace/Microsoft 365 account) and check the headers: `spf=pass`, `dkim=pass`, `dmarc=pass`, the two `List-Unsubscribe` headers, and that the message is multipart/alternative.
