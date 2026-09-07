# Techzapp Mailer

Production-grade WordPress bulk mailer built directly on the **AWS SES v2 API**
with native Signature Version 4 signing — no AWS SDK, no Guzzle, no dependency
tree to collide with other plugins.

Its defining feature is a **hard 2.0% bounce-rate circuit breaker** that halts
all sending automatically before AWS puts your account under review.

## Features

- **CSV importer** — local `is_email()` validation, case-insensitive dedupe
  against the file and the database, automatic delimiter and BOM detection, and
  a unique 128-bit unsubscribe token generated per contact.
- **Campaign composer** — the standard WordPress visual/HTML editor with
  `{name}`, `{email}` and `{unsubscribe_url}` merge tags.
- **Throttled queue worker** — a configurable micro-batch every five minutes
  with a deliberate pause between individual sends. Default ≈180 emails/hour.
- **Circuit breaker** — re-evaluated before *every single message* and again
  whenever bounce data arrives over SNS. Requires an explicit manual
  confirmation to clear.
- **SNS webhook** — records bounces, complaints and delivery confirmations,
  auto-confirms its own topic subscription, and verifies Amazon's message
  signature so the public endpoint cannot be used to forge events.
- **RFC 8058 one-click unsubscribe** — `List-Unsubscribe` and
  `List-Unsubscribe-Post`, required of bulk senders by Gmail and Yahoo since
  February 2024.
- **Dashboard and audit log** — KPI cards, live bounce-rate indicator, and a
  searchable, paginated delivery log with colour-coded event badges.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- An AWS account with SES out of the sandbox and a verified sending domain

## Installation

Download `tz-mailer.zip` from the [latest release][releases] and install it
through **Plugins → Add New → Upload Plugin**.

Once installed, the plugin updates itself from this repository — see below.

## Updates

This plugin is not hosted on wordpress.org. It checks this repository's
releases instead and feeds the result into the normal WordPress update
pipeline, so update notices, the changelog modal and one-click updating all
work exactly as they do for any other plugin.

- Checks run on WordPress's usual update schedule, cached for 6 hours.
- Failed lookups are cached for 30 minutes so a site can never exhaust the
  anonymous GitHub API rate limit.
- Drafts and pre-releases are never offered as updates.
- **Plugins → Techzapp Mailer → Check for updates** forces an immediate check.

### Publishing a new version

```bash
# 1. Bump the Version header in tz-mailer.php
# 2. Commit
git commit -am "Release 1.0.1"

# 3. Tag and push — CI verifies the tag matches the header, lints every
#    PHP file, builds tz-mailer.zip and publishes the release
git tag v1.0.1
git push origin main --tags
```

Every site running the plugin picks the update up on its next check. The tag
must match the `Version:` header or the workflow fails deliberately, which
prevents shipping a release that WordPress would never offer.

## AWS setup

Sending stays inert until this is done. The SNS step is the one people skip —
**bounce data arrives only over SNS, so without it the bounce count stays at
zero and the circuit breaker cannot protect you.**

1. Verify your sending domain in SES and publish the DKIM CNAME records.
2. Publish SPF and DMARC records for the domain.
3. Create an SNS topic, e.g. `techzapp-ses-events`.
4. Subscribe the topic over **HTTPS** to the webhook URL shown in
   **TZ Mailer → Settings**. It confirms itself within seconds.
5. Create an SES configuration set with an SNS event destination pointing at
   that topic, ticking **Bounce**, **Complaint** and **Delivery**.
6. Enter the configuration set name in Settings and send a test email.
7. Request production access to leave the SES sandbox.

Use a dedicated IAM user whose only permission is `ses:SendEmail`.

## Deliverability notes

- Send bulk mail from a dedicated subdomain such as `updates.example.com` so a
  reputation problem on the bulk stream never contaminates transactional mail.
- Ramp volume gradually over two to four weeks on a new sending domain.
- Keep spam complaints below 0.10%. Gmail treats 0.30% as a hard failure.
- The plugin refuses to send any campaign missing `{unsubscribe_url}`, and the
  starter template ships with a placeholder postal address that **must** be
  replaced with a real one to satisfy CAN-SPAM.

## License

GPL-2.0-or-later

[releases]: https://github.com/appcoderikbal/plugin-crm/releases/latest
