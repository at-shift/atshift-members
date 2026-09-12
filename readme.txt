=== atshift Members ===
Contributors: atshift
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.10
License: GPLv2 or later

Email-verified membership, protected content, private attachments and optional profile integration. Development beta.

== Installation ==
1. Install this directory. atshift User Profile Fields is optional for custom profile fields.
2. Activate atshift Members on a single-site InnoDB WordPress installation.
3. Save the Cloudflare Turnstile site key and secret key under Members > Registration settings. Server-managed sites can instead define ASM_TURNSTILE_SITE_KEY and ASM_TURNSTILE_SECRET in wp-config.php; these take priority and hide the key-editing controls. HTTPS is required except WP_ENVIRONMENT_TYPE=local. Production rejects Cloudflare test keys.
4. In Members, create the five account pages. Without a profile integration, registration requires only email and password by default. Optional native profile fields can be selected. When atshift User Profile Fields is connected, its profile configuration takes priority.
5. Verify cron, email delivery, cache exclusion and all other public registration routes before enabling registration. No automatic deployment is included.

== Roles ==
Administrator: site configuration, member and content management.
Site operator: member suspension/reactivation and content management; no plugin/settings/administrator control.
User (posting): owns member posts and public pages. An operator may additionally permit member announcements, without granting editing of others' content or member administration.

== External services ==
Cloudflare Turnstile validates anti-abuse tokens via Siteverify. Pwned Passwords receives only a five-character SHA-1 prefix and returns a padded range response. Password checking fails closed on outage (5-second timeout); explicit ASM_PWNED_PASSWORDS_ENABLED=false disables this check. WordPress mail uses the site's configured transport. These services have not been tested with live credentials in this beta.

== Privacy and access ==
Requests retain email addresses for at most 30 minutes plus cron delay; completion proofs last 15 minutes. Secret proofs are stored only as hashes. Source/email/cookie counters are keyed hashes. Audit events expire after 7 days plus cron delay. Hashing is pseudonymization, not anonymization. Raw tokens must be redacted in web-server/access/analytics logs outside WordPress; confirmation pages send no-referrer/no-store headers.
Ordinary uploaded files remain public. Private attachments use an explicitly configured nonpublic folder on the WordPress server, or a mounted file-server/NAS folder; each download checks membership and parent access. Cloud storage and bulk member email delivery are not included in this free plugin. Public content can remain visible to logged-out visitors even when a user is suspended. CDN/page caches and third-party API/profile/SEO plugins need separate integration verification.

== Shortcodes ==
[asm_registration]
[asm_account]
[asm_account_edit]
[asm_password_reset]
[asm_withdraw]


== Withdrawal ==
Default is permanent account/data erasure, with optional explicit transfer of selected contributions to a non-login site custodian. A separate final confirmation and login within the last ten minutes are required. Selected posts become site-owned drafts; unselected owned content and the account are deleted. Shared physical files and external/backed-up data have explicit limits shown before confirmation. Large deletion jobs continue via WP-Cron. This development alpha is not a blanket erasure guarantee for arbitrary third-party plugins.

Staff are appointed from existing members in Members > Member management. The former operator invitation tab is no longer provided. The operator-only registration page and invitation email editor are also omitted.

== Service terms and privacy ==
Cloudflare Turnstile loads its browser script on challenge-enabled forms and sends the challenge token and configured secret to Cloudflare for verification when a registration or reset request is submitted. Cloudflare may process browser/network signals to prevent abuse.
Terms: https://www.cloudflare.com/terms/
Privacy: https://www.cloudflare.com/privacypolicy/

Pwned Passwords is queried when a password is validated. Only the first five hexadecimal characters of its SHA-1 hash are sent; the password and full hash are never sent. This check is enabled by default and can be disabled with ASM_PWNED_PASSWORDS_ENABLED=false.
Service: https://haveibeenpwned.com/Passwords
Terms: https://haveibeenpwned.com/TermsOfUse
Privacy: https://haveibeenpwned.com/Privacy


== Changelog ==
= 0.1.10 =
* Add registration usernames, standalone name ordering and a separate email-change screen.
* Improve member status controls, administrator-only bans and staff handoff safeguards.
* Improve profile integration, member classification wording and Japanese translations.
* Add media preparation and attachment access integration hooks for optional add-ons.
