# atshift Members

**0.1.10 — public beta**

A WordPress membership plugin with email-verified registration, account management, protected content, and private attachments.

## Features

- Registration with email verification and optional operator approval.
- Account information, profile editing, password reset, and account closure.
- Posting permissions and member-only content access.
- Private file storage on the WordPress server or a mounted file server/NAS share.
- English source strings and bundled Japanese translations, including the media picker.

## Optional integrations

- **atshift User Profile Fields 1.1.3:** use a configured profile for registration, account display, and editing.
- **atshift User Profile Fields / Pro Add-on 1.1.3:** user classifications and category-based posting permissions.
- **atshift Freeform Login 2.3.3:** passkey registration and management on the account editing page. This integration also works without a profile plugin.
- **atshift Members Pro Add-on:** additional approval and delegated-management features. The add-on is a separate product and is not included here.

## Requirements and setup

WordPress 6.6 or later, PHP 8.1 or later, and InnoDB tables. This beta supports single-site installations. Freeform Login passkeys require its own supported environment, including PHP 8.3 or later.

Install the plugin directory as `wp-content/plugins/atshift-members`, activate it, and use the Members settings to prepare the account pages and Cloudflare Turnstile keys. HTTPS is required except in a local development environment. Exclude member pages from page caching.

Private attachments require a readable and writable directory outside the public web directory. A NAS or file server share must already be mounted on the WordPress server. Cloud storage integration is not included in this beta.

See [readme.txt](readme.txt) for setup, external services, and data handling, and [translation maintenance](docs/I18N.md) for catalog updates.

## Validation and beta status

The local test environment used WordPress 7.1 and PHP 8.3.31. Plugin Check completed with no errors and five query-performance warnings. Integration checks covered registration, profiles, categories, delegated scopes, and final approval notifications. Passkey integration used synthetic credentials; physical authenticator registration/login, live email delivery, real NAS/VPN deployments, and large-scale performance were not verified.

Saved email templates and user-entered content are not rewritten when the interface language changes. Default outgoing emails follow the WordPress locale of the sending process.

This is a beta release. Evaluate it on a test site before adopting it for a live membership service.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
