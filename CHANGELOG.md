# Changelog

All notable changes to Bastion are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

## [1.0.0] — 2026-10-28

First release.

### Added

- **Two modes.** `audit` (the default) measures and reports and changes nothing on the front end; `apply` lets the switches take effect. Every feature asks the same gate, so audit really is read-only.
- **HTTPS redirect** with a choice of status code, a deliberate exemption for `POST` (a 301 would turn it into a GET and lose the body), reverse-proxy support restricted to a declared list of trusted peers, and a **dry run** that logs instead of redirecting.
- **HSTS** with configurable max-age, `includeSubDomains` and `preload` as separate choices, sent over TLS only, and off by default.
- **Security headers**: Content-Security-Policy in report-only or enforcing mode with three presets, `X-Frame-Options` kept consistent with the CSP `frame-ancestors` directive, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy` (off by default), and `X-XSS-Protection: 0`.
- **SPF and DMARC records** generated for the domain, with provider detection from the MX record, and the published values read back through a real DNS query. The progression `~all` → `-all` and `p=none` → `quarantine` → `reject` is guided on screen.
- **Disclosure removal**: generator meta, `?ver=` replaced by a hash of the file (so cache busting still works), RSD and WLW manifest links, shortlink, `X-Pingback`, `X-Powered-By`, and a 404 for `readme.html`, `license.txt` and `wp-config-sample.php`.
- **The audit**: nineteen checks in four groups, each with its status, its evidence and the remedy. It fetches the site's own pages and reads the headers that really came back, then queries DNS. Exportable as Markdown from the screen or with `wp bastion audit --format=markdown`.
- **WP-CLI**: `wp bastion audit`, `settings`, `enable`, `disable`, `audit-only`.
- **Capped activity log** with no visitor address and no personal data.
- **Self-update from GitHub releases**, switchable off.
- **Emergency disable**: `define( 'BASTION_DISABLED', true );`, checked once, in memory.
- English source strings with complete French and Spanish catalogues, built from the source by `tools/build-translations.php`.
- 205 assertions: 162 unit, 43 over a booted plugin.

### Fixed during development

- The audit read the response headers with an array cast on WordPress's `CaseInsensitiveDictionary`, which exposes its private properties: every header check reported "absent". It now goes through `getAll()`.
- The audit probed `https://` on an install that serves `http://`, so on such a site every check reported "unreachable". It now measures the URL the site actually serves, and probes TLS separately.
- A check that could not run — site unreachable, DNS unavailable — was reported as a pass for the disclosure group. It is now a warning, so nothing turns green by default.

[1.0.0]: https://github.com/zigmoon/bastion/releases/tag/v1.0.0
