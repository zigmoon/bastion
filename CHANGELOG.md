# Changelog

All notable changes to Bastion are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[semantic versioning](https://semver.org/).

## [1.1.3] — 2026-10-29

### Changed

- **A new mark.** The battlemented wall is gone. The icon is now a single filled gate: the opening and the crown are concentric arches with one spring line, so the band is 56 px wide at the apex, down both piers and into the base — nothing thicker than anything else, which is what keeps a mass this heavy from looking clumsy. A solid silhouette replaces the line drawing because at 32 px a stroke of a few pixels turns to grey.
- `tools/build-icon-master.ps1` now draws that path instead of the old wall, and states the shared geometry in its header. Both renders — the SVG and the GDI+ raster — were measured at 512 px: 15.24 % and 15.16 % ink, so the two drawings are the same drawing.

## [1.1.2] — 2026-10-29

### Fixed

- **The updater answered for a release that was not newer.** WordPress puts whatever the `update_plugins_github.com` filter returns into the update list, so returning the latest release unconditionally could badge a plugin that was already current — and reinstalling the same version is how a site gets rolled back by accident. The answer is now gated on `version_compare`, and there is a test for the equal and the newer case.
- Five assertions added, 254 in total.

## [1.1.1] — 2026-10-29

### Fixed

- **The changelog in the "View details" modal was raw Markdown.** A release body is written in Markdown, and passing it through as text left `**asterisks**`, backticks and bullet markers on screen — in the one place a visitor decides whether to update. Headings, bold, inline code and lists are now converted to the small HTML the modal accepts, and everything is escaped: a release body containing HTML is shown as text, not rendered.

### Added

- **The self-updater is tested.** Version normalisation, asset selection (a whole-repository archive is skipped, an older plugin archive stays a usable fallback, a payload with nothing to install is refused), drafts and pre-releases declared unofferable, and the changelog conversion above. 23 new assertions, 249 in total.
- `tests/bootstrap.php` now reads `BASTION_VERSION` from the plugin header instead of keeping its own copy, so the suites cannot pass against a version the plugin does not ship.

## [1.1.0] — 2026-10-29

Fixes found by re-reading the plugin and running it on a real WordPress 7.1.4.

### Fixed

- **The HTTPS redirect never covered the login screen.** `wp-login.php` is a standalone file: it fires `login_init`, never `template_redirect`, so the redirect that protected the front end left the one page that carries a password in clear text completely untouched. The hook is now registered on both.
- **The redirect dropped a non-standard port.** `http://host:8080/page` was redirected to `https://host/page`, which on a staging install is a redirect to nothing. The port of the configured site URL is now kept; port 80 alone is dropped, because HTTPS answers on 443.
- **The CSP "safe" preset could break a page.** It carried `object-src 'none'`, which stops `<embed>` and `<object>` from loading a PDF, and `form-action 'self'`, which stops a checkout page from posting to a payment gateway. Both moved to the strict preset.
- **`Permissions-Policy` disabled `payment`**, which silently breaks Apple Pay, Google Pay and Stripe's express checkout. The default is now `camera`, `microphone` and `geolocation` only.
- **`X-Powered-By` was only removed when the header switches were on.** It is a disclosure, not a header policy: it is now removed independently.
- **A dry run wrote to the options table on every single page view.** High-frequency events are throttled to one entry per minute per event and detail.
- **The welcome notice came back on every page.** `is-dismissible` only hides a custom notice client-side; there is now a real Dismiss button with a nonce and an `admin_post_` handler that records the choice.
- The audit reported a report-only CSP as a pass. Nothing is blocked in report-only mode, so it is now reported as *to review*.

### Added

- **New audit check: "Login screen is not served in clear text"** — the login URL is fetched over `http://` on its own, because a redirect that covers the front end but not `wp-login.php` protects the least interesting surface.
- `Https::port_suffix()`, `Headers::response_headers()` and a `login` measurement in the audit probe.
- The self-updater is now tested: version normalisation, asset selection (a whole-repository archive is skipped, an older plugin archive is a usable fallback), drafts and pre-releases refused, and the Markdown of a release body converted to the small HTML the modal accepts — headings, bold, inline code, lists, all escaped.
- 21 new assertions, 226 in total.

### Changed

- `HSTS` is now decided by `Https::is_secure()` rather than `is_ssl()`, so a site behind a TLS-terminating CDN still gets the header.
- Mixed content is reported as *not applicable* rather than *pass* when the site itself is served over HTTP.
- The `force_https` and `block_readme` help texts state the real limits instead of a promise.

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

[1.1.3]: https://github.com/zigmoon/bastion/releases/tag/v1.1.3
[1.1.2]: https://github.com/zigmoon/bastion/releases/tag/v1.1.2
[1.1.1]: https://github.com/zigmoon/bastion/releases/tag/v1.1.1
[1.1.0]: https://github.com/zigmoon/bastion/releases/tag/v1.1.0
[1.0.0]: https://github.com/zigmoon/bastion/releases/tag/v1.0.0
