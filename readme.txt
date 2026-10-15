=== EasyFormly ===
Tags: contact form, antispam, mailerlite, slack, conditional fields
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Forms with local anti-spam, international phone fields, conditional fields, integrations, and a native WordPress interface.

== Description ==

EasyFormly is a standalone WordPress plugin with a small tag editor, native metaboxes, AND/OR conditional fields, and plain-text email notifications.

* Private custom post types and native metadata for form definitions and the optional submission archive.
* No custom database tables, bundled frameworks, or dependencies on Contact Form 7 or Forminator.
* Equal-width field columns using | between tags on the same line, stacked on small screens.
* Submission storage is disabled by default and can be configured globally or per form.
* Three local anti-spam levels, a rotating honeypot, rate limits and explainable scoring without AI.
* Private Accepted, Probable spam and Spam categories with filters, bulk review and explicit approval before held delivery.
* International phone selector based on the site's language, numbering-plan validation and E.164 normalization.
* Optional DNS checks, public disposable-email lists with manual synchronization and configurable suspect phrases.
* Retention settings and integration with WordPress personal-data export and erasure tools.
* Optional Cloudflare Turnstile per form, with server-side validation of token, hostname, and action.
* MailerLite: map email and optional name to one or more groups, gated by a newsletter consent checkbox.
* Slack: a webhook and a selection of included fields for each form.
* Durable one-hour submission receipts and atomic ownership in native WordPress options to limit duplicate concurrent deliveries.
* Per-destination delivery status and bounded recovery from the optional submission archive.
* Data is retained on uninstall unless an administrator explicitly opts into deletion.

This is an original implementation. Contact Form 7 and Forminator are functional references and are not included as dependencies.

= External services =

The plugin has no telemetry. External requests depend on the form's configuration:

**Optional email DNS check**: when explicitly enabled, the server asks its DNS resolver for the email domain's MX/A/AAAA records. The mailbox name and message are not sent. Results are cached for up to fifteen minutes; resolver errors are treated as inconclusive. This does not prove that the mailbox exists. Phone validation and disposable-domain checks use local data.

**Manual disposable-domain synchronization**: only when an administrator presses Sincronizza lista, the server downloads the public lists from disposable-email-domains/disposable-email-domains and disposable/disposable-email-domains through raw.githubusercontent.com. No submitted address, mailbox name or message is sent. Both sources are validated before replacing the non-autoloaded local cache; failures keep the last complete list. There is no synchronization cron, automatic download or remote lookup during form submissions. The plugin includes a combined snapshot for use before the first synchronization. Sources and licenses are documented in THIRD-PARTY.md.
Terms: https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
Privacy: https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement

**MailerLite**: after a valid submission, if the integration is enabled and newsletter consent is checked, the server sends the email address, optional name, and group IDs to the current MailerLite API. The plugin sends neither a status nor a resubscribe flag, so it never asks MailerLite to reactivate opted-out subscribers. Double opt-in is configured in the MailerLite account settings for API and integrations. MailerLite Classic is not supported in this version.
Documentation: https://developers.mailerlite.com/api/subscribers
Terms: https://www.mailerlite.com/legal/terms-of-service
Privacy: https://www.mailerlite.com/legal/privacy-policy

**Slack**: after a valid submission, if enabled, the server sends the form title and values of only the selected visible fields to the configured hooks.slack.com webhook. User content is sent in plain-text blocks so it cannot generate mentions or execute markup.
Documentation: https://docs.slack.dev/messaging/sending-messages-using-incoming-webhooks/
Terms: https://slack.com/terms-of-service
Privacy: https://slack.com/trust/privacy/privacy-policy

**Cloudflare Turnstile**: when viewing a form with CAPTCHA enabled, the browser loads the widget from challenges.cloudflare.com. The widget can process browser and connection information according to Cloudflare's privacy policy. On submission, the server sends the response token and secret key to the verification API. The plugin does not include the visitor IP address in that verification request.
Documentation: https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
Terms: https://www.cloudflare.com/website-terms/
Privacy: https://www.cloudflare.com/privacypolicy/ and https://www.cloudflare.com/turnstile-privacy-policy/

WordPress erasure removes local archived copies only. Copies already sent by email or to MailerLite or Slack must be managed in those services.

== Installation ==

1. Upload the easyformly plugin folder or its ZIP from Plugins > Add Plugin, and activate it.
2. Open EasyFormly > Impostazioni to configure credentials and preferences.
3. Create and publish a form from EasyFormly > Moduli.
4. Add the shortcode [easyformly id="123"] to a page, replacing 123 with the form ID.

The interface is in English and includes an Italian translation, used automatically when the site or user language is Italian. A separate Italian setup guide is provided with this build.

== Frequently Asked Questions ==

= How can fields share a row? =
Place | between field tags on the same line. Two fields share the row equally (50% each), three use one third each (33.33%), with spacing between columns:

[text* nome "Nome"] | [email* email "Email"]

Or:

[text* nome "Nome"] | [text* cognome "Cognome"] | [email* email "Email"]

Start a new line for the next row. Notes and the submit button remain separate. On screens up to 600px wide, fields stack vertically. Conditional fields retain their visibility rules; the remaining visible fields share the row's available space.

= Does the plugin create custom tables? =
No. Forms, optional submissions, settings, temporary ownership records, and receipts use core WordPress tables. Credentials, receipts, and ownership records are not autoloaded. There are no runtime package dependencies.

= How do the anti-spam levels work? =

Level 1 is basic protection: rotating honeypot, signed submission ticket, required fields, valid email syntax, international phone validation and IP rate limits.

Level 2 adds disposable email domains, entirely repetitive phone numbers, link density, recurring promotional patterns, custom suspect phrases, repeated contacts and similar message campaigns. Level 3 applies lower suspicion thresholds, lower frequency limits and a stricter submission limit. A single weak signal such as very fast typing never blocks an otherwise clean submission.

New forms inherit the site's level (2 by default). Observation mode records heuristic detections without holding delivery; honeypot and structural validation remain enforced. Enable the normal archive to inspect observation results.

The plugin does not use AI. It cannot establish mailbox ownership, whether a number is active, or reliably judge the relevance of every message. Random-looking email aliases are not rejected solely because they look unusual. Customize specific phrases and review quarantine to tune the rules for the site.

= How do I update the disposable-email list? =

Open EasyFormly > Impostazioni > Lista domini temporanei and press Sincronizza lista. The screen shows the source projects, domain count and last synchronization or bundled snapshot date. The list combines disposable-email-domains, also used by PyPI, and the normal list from disposable/disposable. Updates respect provider additions and removals; if either source fails validation, the current complete copy remains active. No cron or visitor request downloads these lists.

= Where are suspicious submissions organized? =

Archived entries have one private category: Accepted (Accettato), Probable spam (Probabile spam), or Spam. The entry list has category filters and counts, badges and bulk actions. Each entry shows its original automatic score and reasons, and whether a manual decision has replaced the category.

Spam and suspect entries are held before email, MailerLite or Slack delivery. When the normal archive is enabled they are saved there. With the normal archive off, enable the separate quarantine preference to save only suspicious entries. With both off, suspicious submissions are refused and no contact copy is created. Neither archive option is enabled by default.

Changing category never releases a held delivery. Use Approva e invia to approve and send it with the current form configuration; destinations that already accepted it are skipped. Automatic retries cannot approve spam. Quarantined entries have separate retention (14 days by default) and a site-wide cap (500 new held entries per rolling 24 hours by default). Ordinary entries follow normal retention (30 days by default). Configure shorter or longer periods in Settings.

= How do international telephone fields work? =

Use [tel* telefono "Telefono"]. The country starts from the site's locale (for example it_IT uses Italy, en_GB uses the United Kingdom), with configurable site, form and field overrides. Visitors can search and select another country. National numbers and international +/00 prefixes are supported; valid numbers are stored and delivered in E.164 form. Both landline and mobile numbers are accepted by default.

[tel* telefono "Telefono" country:IT mobile]

country:IT overrides the initial country; mobile restricts the field to mobile-compatible numbering ranges. Optional allow-repeated permits an entirely repetitive number for that specific field. This exception does not bypass international validity checks. Numbering metadata is supplied by the locally bundled libphonenumber library; its source and licenses are documented in THIRD-PARTY.md.

= Are IP addresses or user agents recorded? =
Not in archived submissions. A keyed fingerprint of the IP address is kept for five minutes to throttle repeated submissions; the raw IP is not stored. Levels 2 and 3 also keep keyed email/phone counters for ten minutes and normalized message fingerprints for thirty minutes. Submission receipts expire after one hour and contain keyed fingerprints, safe delivery codes, attempt counts, an optional native archive ID, the rule assessment, and a confirmation response. They contain no submitted field values or credentials. Expired records are physically removed by scheduled cleanup and WordPress transient maintenance. Web servers and external services can maintain their own records.

= Are hidden fields submitted? =
No. The server recalculates conditional visibility and excludes hidden field values from email, storage, and integrations.

= How are notifications delivered? =
Services are initially contacted during submission. A submission is acknowledged when at least one destination accepts it or local storage saves it. Delivery status is shown in the native form and submission lists and in their details. An email accepted by wp_mail is not proof of inbox delivery.

Suspicious submissions are acknowledged as awaiting review when saved in quarantine. They are not forwarded until approved by an administrator.

When the optional archive is enabled, recoverable MailerLite failures and explicit Slack rate-limit responses can be retried through WP-Cron. There are at most three background attempts, scheduled with delays of one, five, and fifteen minutes, within a twenty-four-hour recovery window. Attempts skip destinations that already accepted the submission. Updating a form's configuration stops its existing background recovery jobs.

Ambiguous email or Slack outcomes are not retried automatically. Administrators can request recovery from an archived entry. Resending an ambiguous destination requires an explicit confirmation that its previous delivery was checked. Manual recovery uses the current form configuration. Deleting, trashing, or erasing an archived entry cancels its scheduled recovery.

With the archive disabled there is no background delivery queue and no saved copy of the submitted fields. No plugin can guarantee exactly-once delivery across remote services or during arbitrary server failures.

= What happens to an old browser tab or an interrupted submission? =
The browser refreshes an expired submission ticket before a first send and preserves field values. After an interrupted request it reuses the original ticket and payload to retrieve a stored confirmation. If an ambiguous attempt has already expired, it asks the visitor to contact the site before sending again.

= Are translations supported? =
All interface strings are in English and use the easyformly text domain. The package includes a POT template and the Italian translation (languages/easyformly-it_IT.po and .mo). Translations in WordPress's language directory take precedence over the bundled one.

= Can forms be imported from other plugins? =
This version uses its own small tag syntax. It does not include automatic imports, a drag-and-drop builder, file uploads, payments, multiple steps, or separate confirmation emails to the submitter.

= Does it support multisite? =
Settings, submissions, signed tickets, and background recovery are scoped per site. Network activation initializes each site on its first visit. Uninstall honors each site's data-removal preference. Retention and the cleanup of expired technical records run in small batches when an administrator opens the dashboard and after a submission, at most once a day, without WP-Cron. Delivery retries depend on WP-Cron being operational. Deactivation stops the plugin's scheduled retries; the next maintenance run after activation or an update resumes eligible archive jobs. This build's functional verification was performed on a single-site installation.

== Changelog ==

= 1.0.0 =
Initial release: tag editor with equal-width rows, AND/OR conditional fields, plain-text email, MailerLite, Slack and Cloudflare Turnstile. Local anti-spam levels 1–3 with rotating honeypot, explainable scoring, observation mode, bundled disposable-domain list with manual synchronization, and international phone validation. Optional archive with Accepted, Probable spam and Spam categories, explicit quarantine approval, bounded per-destination recovery, retention settings and WordPress privacy export/erasure. Native WordPress storage only, no custom tables.
