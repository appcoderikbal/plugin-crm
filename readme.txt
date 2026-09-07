=== Techzapp Mailer ===
Contributors: techzapp
Tags: email, newsletter, aws, ses, bulk email, deliverability, bounce handling
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Production-grade bulk mailer built directly on the AWS SES v2 API, with a hard
2.0% bounce-rate circuit breaker that protects your SES account automatically.

== Description ==

Techzapp Mailer sends throttled bulk email through Amazon SES using native
Signature Version 4 request signing. It does not bundle the AWS SDK.

Core features:

* CSV importer with local validation, de-duplication and per-contact
  cryptographic unsubscribe tokens.
* Campaign composer built on the standard WordPress visual/HTML editor, with
  {name}, {email} and {unsubscribe_url} merge tags.
* Throttled WP-Cron queue worker: a configurable micro-batch every five
  minutes with a deliberate pause between individual sends.
* Real-time circuit breaker that halts all sending the moment the rolling
  bounce rate reaches 2.0%, and requires an explicit manual confirmation to
  clear.
* AWS SNS webhook that records bounces, complaints and delivery confirmations,
  auto-confirms its own topic subscription, and verifies Amazon's message
  signature.
* RFC 8058 one-click unsubscribe with List-Unsubscribe and
  List-Unsubscribe-Post headers, as required of bulk senders by Gmail and
  Yahoo since February 2024.
* Dashboard KPIs and a searchable, paginated delivery audit log.

== Installation ==

1. Upload the `tz-mailer` folder to `/wp-content/plugins/`.
2. Activate the plugin. Three tables are created and the queue worker is
   scheduled automatically.
3. Go to TZ Mailer > Settings and enter your AWS credentials, region and
   verified From address.
4. Copy the SNS Webhook URL shown on that screen into an Amazon SNS HTTPS
   subscription, and point an SES configuration set event destination at that
   topic. Without this step no bounce data reaches the plugin and the circuit
   breaker cannot protect you.
5. Import contacts under TZ Mailer > Upload CSV.
6. Write and activate a campaign under TZ Mailer > Campaign Composer.

== Frequently Asked Questions ==

= Why does sending stop on its own? =

The circuit breaker halts everything once the rolling bounce rate reaches
2.0% across at least 50 processed contacts. AWS places accounts under review
at 5% and can suspend at 10%, so this stops you well before Amazon acts.
Clear it from Settings after cleaning the list.

= Why will the plugin not send my campaign? =

Campaigns must contain the {unsubscribe_url} merge tag. Sending bulk email
without a working unsubscribe link violates CAN-SPAM and the Gmail and Yahoo
bulk sender rules, so the worker refuses to send and deactivates the campaign.

= How fast does it send? =

Batch size multiplied by twelve runs per hour. The default of 15 works out to
roughly 180 emails per hour. Ramp this up gradually on a new sending domain.

= Does deleting the plugin destroy my list? =

Only if you tick "Delete all data" in Settings first. Deactivation never
touches your data.

== Changelog ==

= 1.0.0 =
* Initial release.
