=== atshift Members ===
Contributors: atshift
Tags: membership, member directory, private content, user registration, access control
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build a simple membership site with verified registration, member pages, posting controls, a directory, and private files.

== Description ==

atshift Members provides a compact foundation for a straightforward WordPress membership site. It keeps member registration, account management, member posting, protected content, an optional directory, and private attachments in one place.

Members can create their own permitted posts and attach images or documents stored separately from the public WordPress Media Library. Each private download checks the member's current status and access to the parent content.

Official website: [atshift Members](https://plugins.at-shift.net/en/members/) | [Japanese](https://plugins.at-shift.net/members/)

Documentation: [Setup guide](https://plugins.at-shift.net/en/members/guide/) | [Feature and shortcode reference](https://plugins.at-shift.net/en/members/reference/) | [Pro Add-on](https://plugins.at-shift.net/en/members/pro/)

Japanese documentation: [Guide](https://plugins.at-shift.net/members/guide/) | [Reference](https://plugins.at-shift.net/members/reference/)

= Set up the member experience =

* Create the registration, account, account-editing, password-reset, and account-closure pages together.
* Add a member home page with links to the account and the post types each member can use.
* Accept registrations immediately or hold new accounts for an administrator to approve.
* Use email confirmation for registration, email-address changes, and password resets.

= Manage members and content =

* Activate, suspend, or return accounts to pending status from a dedicated member list.
* Allow active members to create selected WordPress post types while keeping them from editing other users' content.
* Protect selected posts or pages for active members without changing public content elsewhere on the site.
* Hide standard author archives and username-based author links.

= Publish an optional member directory =

The directory is disabled by default. When enabled, it can be public, restricted to active members, restricted to site operators, or limited through an optional classification integration. Choose whether to display a member's name, affiliation, and biography, and exclude individual accounts such as site builders.

= Store member-only attachments =

Private attachments can be stored in a non-public directory on the WordPress server or on a file-server/NAS share already mounted by the host. Each download checks the current member and the parent content. Ordinary WordPress uploads remain public.

= Extend atshift Members =

atshift Members works on its own with basic name and biography fields and keeps the core membership-site structure simple. Separate add-ons and related plugins let organizations and businesses strengthen only the parts of operation they need.

* [atshift Members Pro Add-on](https://plugins.at-shift.net/en/members/pro/) - strengthens operations for organizations and businesses with staged post and membership-change review, delegated responsibilities and management scopes, targeted announcement delivery and scheduling, CSV member invitations, and a workspace where members can follow posts, private attachments, and approval history.
* [atshift User Profile Fields](https://wordpress.org/plugins/atshift-user-profile-fields/) - strengthens member profiles with additional fields such as telephone numbers and addresses and lets administrators arrange the member-facing profile form.
* [atshift User Profile Fields Pro Add-on](https://plugins.at-shift.net/en/pro/) - strengthens member organization with hierarchical classifications such as branches, departments, and membership types. Compatible classifications can also be used for posting rules, directory audiences, and Members Pro management scopes.
* [atshift Freeform Login](https://wordpress.org/plugins/atshift-freeform-login/) - strengthens the sign-in experience with a customizable login screen plus password and passkey registration, login, and management.

Each add-on and related plugin is a separate product and is not included in this plugin.

The current release supports WordPress single-site installations. Test the complete registration, email, cache, content, file-storage, and account-closure flows on a staging site before opening registration.

== Installation ==

1. Install and activate atshift Members on a single-site WordPress installation using InnoDB tables.
2. Open Members > Member Page Setup and create the five member pages. Keep these pages published; the plugin restricts account details and forms to the appropriate user.
3. Open Members > Registration Settings and choose whether to accept registrations and require administrator approval.
4. Add Cloudflare Turnstile site and secret keys. Server-managed sites can define `ATSHME_TURNSTILE_SITE_KEY` and `ATSHME_TURNSTILE_SECRET` in `wp-config.php`; constants take priority and hide the key controls.
5. Review Members > Posting Settings, email templates, directory settings, and private-file storage.
6. Exclude member pages from page caches and shared CDN caches. Verify cron and outgoing email before enabling registration.

HTTPS is required except when `WP_ENVIRONMENT_TYPE` is `local`. Production sites reject Cloudflare test keys.

== Frequently Asked Questions ==

= Can I use the plugin without another profile plugin? =

Yes. atshift Members can collect a name, display name, and biography on its own. atshift User Profile Fields is optional when you need additional or rearranged fields.

= Can members publish WordPress posts? =

Yes. Posting Settings lets an administrator choose which registered post types active members may use. A grant covers a member's own content and does not grant permission to edit other users' posts. Reading permissions are configured separately.

= Is the member directory public? =

Only if an administrator enables it and selects Everyone. The directory is disabled by default. Login names, email addresses, and administrative permissions are not directory fields.

= Are normal Media Library uploads private? =

No. Standard WordPress uploads remain public. Member-only files must use the configured private storage and the member-only attachment controls.

= Does the free plugin send bulk announcements? =

No. The free plugin sends account-related transactional email. Announcement delivery, scheduling, post review, and staff responsibility assignment are available through the separate atshift Members Pro Add-on.

= Does it support multisite? =

Version 1.0 supports single-site installations.

== Screenshots ==

1. Create and review the five required member pages and an optional member home page.
2. Choose registration availability, administrator approval, and the profile fields used by member forms.
3. Set member posting permissions independently for each registered post type and hide username-based author links.
4. Search and manage members, account status, roles, and available post types from one list.
5. Give each active member a simple home page with account and posting links relevant to that user.
6. Publish an optional directory with an administrator-controlled audience, fields, and per-user exclusions.

== Shortcodes ==

Core account pages:

* `[atshme_registration]`
* `[atshme_account]`
* `[atshme_account_edit]`
* `[atshme_password_reset]`
* `[atshme_withdraw]`

Member navigation and directory:

* `[atshme_dashboard]`
* `[atshme_account_links]`
* `[atshme_post_links]`
* `[atshme_approval_status]` (shows Pro approval data when the add-on is active)
* `[atshme_member_directory]`

The dashboard shortcodes accept `class`, `heading_tag`, `title`, and `limit` where applicable. The directory accepts `class`, `heading_tag`, and `per_page`. Shortcode attributes cannot broaden the access configured by an administrator.

See the [feature and shortcode reference](https://plugins.at-shift.net/en/members/reference/) for usage details and examples.

== External services ==

Cloudflare Turnstile is used on registration and password-reset request forms to reduce automated abuse. Its browser script loads when one of these forms is displayed. When a request is submitted, the plugin sends the Turnstile response token and the site administrator's secret key to Cloudflare's Siteverify endpoint. Cloudflare may also process browser and network signals as described in its policies. Registration and password-reset requests cannot proceed when verification fails.

Service: https://www.cloudflare.com/products/turnstile/
Terms: https://www.cloudflare.com/terms/
Privacy: https://www.cloudflare.com/privacypolicy/

Pwned Passwords is queried whenever this plugin validates a new or changed password. The plugin sends only the first five hexadecimal characters of the password's SHA-1 hash to the range endpoint at `api.pwnedpasswords.com`. The password and full hash are never sent. Returned suffixes are used only for the current comparison and are not stored. Password validation fails closed if the service is unavailable (five-second timeout). A site administrator can disable this check by defining `ATSHME_PWNED_PASSWORDS_ENABLED` as `false`.

Service: https://haveibeenpwned.com/Passwords
Terms: https://haveibeenpwned.com/TermsOfUse
Privacy: https://haveibeenpwned.com/Privacy

WordPress mail uses the site's configured mail transport and is not a service supplied by this plugin. Test both external services with credentials for the target site before opening registration.

== Privacy and access ==

Pending requests retain email addresses for at most 30 minutes plus cron delay; completion proofs last 15 minutes. Secret proofs are stored only as hashes. Source, email, and cookie counters use keyed hashes. Audit events expire after seven days plus cron delay. Hashing is pseudonymization, not anonymization. Raw tokens should be redacted from web-server, access, and analytics logs outside WordPress; confirmation pages send no-referrer and no-store headers.

Private downloads recheck membership and parent-content access. Public content can remain visible to logged-out visitors when a member is suspended. CDN and page caches, reverse proxies, and third-party profile, API, and SEO plugins require separate integration testing.

Account closure permanently erases the account and plugin-managed data by default. Members can explicitly transfer selected contributions to a non-login site custodian. Shared physical files, backups, and data stored by unrelated plugins have limits explained before final confirmation.

== Changelog ==

= 1.0 =
* Initial public release with email-verified registration, account management, protected member content, posting permissions, and private attachments.
* Add a member home, optional directory, individual directory exclusions, basic profile presets, and optional integrations with other atshift plugins.
* Add migration from beta identifiers and strengthen authorization, request validation, external-service documentation, and media-page integration.
