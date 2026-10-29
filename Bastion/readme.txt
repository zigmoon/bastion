=== Bastion ===
Contributors: zigmoon
Tags: security, https, hsts, security headers, csp, dmarc
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Forces HTTPS, sets the security headers, closes the version leaks and audits the result on the live site — with nothing applied until you ask.

== Description ==

Bastion hardens a WordPress site without ever surprising it. It ships in **audit mode**: it measures the site, reports what it found, and changes nothing on the front end until you switch to **Apply**.

**HTTPS.** Redirect clear text to TLS with the status code you choose. Only `GET` and `HEAD` are redirected — a 301 on a POST turns it into a GET and loses the form. Behind a CDN or a load balancer, the forwarded scheme is read only from a declared list of trusted addresses, so a visitor cannot claim to be on HTTPS to skip the redirect. A **dry run** logs what would have been redirected before anything moves.

**HSTS.** `Strict-Transport-Security` with a max-age you control, `includeSubDomains` and `preload` as separate, deliberate choices. Off by default, because a browser that has seen it refuses plain HTTP for the whole max-age even after the header is removed.

**Security headers.** Content-Security-Policy in report-only or enforcing mode, with a **safe** preset that cannot break a page (framing, base URI, plugins, form targets — and deliberately no `default-src`), a **strict** preset for a site you control, and a custom policy. `X-Frame-Options` is kept consistent with the CSP `frame-ancestors` directive, so the two can never contradict each other. Plus `X-Content-Type-Options: nosniff` to lock file types, `Referrer-Policy`, `Permissions-Policy`, and `X-XSS-Protection: 0`.

**SPF and DMARC.** The exact TXT records for your domain, written for the provider the MX record points at, with the responsible progression built in: `~all` before `-all`, `p=none` before `quarantine` and `reject`. A plugin cannot publish DNS, and Bastion does not pretend to: it writes the records, then reads back what is actually published through a real DNS query.

**Disclosures.** No generator meta, no `?ver=<core version>` (replaced by a hash of the file, so cache busting still works), no RSD or WLW manifest link, no shortlink, no `X-Pingback`, no `X-Powered-By`, and a 404 for `readme.html`.

**The audit.** Twenty checks in four groups, each with its status, its evidence and the remedy — measured by fetching the site's own pages and reading the headers that really came back, then querying DNS. A check that cannot be measured says so; it never turns green by default. Exportable as Markdown.

**Built for the current stack** — WordPress 6.5 through 7.1, PHP 8.2 through 8.5. Only maintained WordPress APIs, no deprecated calls, no Composer or npm build step. The single outbound request is the update check against GitHub, once every 12 hours, and it can be switched off.

**Quality**

* Strictly escaped output, validated input, capability checks and nonces on every admin action.
* A newline in any header value is removed in sanitisation, never at send time.
* Small, single-responsibility classes: settings, headers, https, disclosure, DNS, audit, admin, CLI.
* 249 automated assertions ship in the `tests/` directory (unit tests plus a boot/render smoke test).
* English source strings, complete French and Spanish translations, `.pot` catalogue.
* Writes no file. Creates no table. `define( 'BASTION_DISABLED', true );` stops everything in memory.

== Installation ==

1. Upload the `Bastion` folder to `/wp-content/plugins/`, or install the ZIP from Plugins → Add New → Upload Plugin.
2. Activate the plugin through the **Plugins** screen.
3. Open **Settings → Bastion** and press **Run the audit**.
4. Read the report. Fix what the server can fix. Then switch to **Apply** and turn on what you need, one group at a time.

The plugin changes nothing on the front end until step 4.

== Frequently Asked Questions ==

= Will activating it break my site? =

No. It ships in audit mode: it measures and reports and does not touch a single response. Every feature asks one gate before acting, and that gate is closed until you choose Apply.

= Can I try the redirect without the risk? =

Yes. Turn the redirect on, leave the **dry run** on, and check the activity log after a day: it lists the requests that would have been redirected. Turn the dry run off when the list makes sense.

= Why is HSTS off by default? =

Because it is the one setting that cannot be undone quickly. A browser that has received the header refuses plain HTTP for the whole max-age, even if you remove it. Start with a short max-age, confirm the site is stable for a week, then raise it. There is a warning on the screen that says exactly this.

= Why is the CSP in report-only? =

Because enforcing a policy you did not write is how a site goes blank. Report-only makes the browser report what it *would* have blocked, without blocking it. The **safe** preset can be enforced immediately: it says nothing about scripts or styles, so it cannot break a page.

= Can the plugin publish my SPF and DMARC records? =

No, and no plugin can. They are TXT records at your registrar. Bastion writes the exact records for your domain — for the provider your MX record points at — and then reads back what is actually published, so the audit tells you the truth.

= Does it remove readme.html? =

It answers 404 for it when the request reaches WordPress. When your web server serves the file directly from disk, delete it or block it in the server configuration — the audit reports which case you are in.

= Does it protect against a brute-force attack or a plugin vulnerability? =

No, and it does not claim to. It is transport, headers, disclosures and mail authentication. Access control is a different job — see VaultGate by the same author.

= Will it conflict with my server's own headers? =

Sending the same header twice with different values is a real risk, so check the audit: it reads the header that actually came back, including the one your server or CDN added. If a header is already set correctly, leave the switch off.

= Does it work behind a CDN or a reverse proxy? =

Yes, with **Trust reverse-proxy headers** on and the CDN's addresses in the trusted list. Without that list, the forwarded scheme is ignored — otherwise any visitor could claim to be on HTTPS to skip the redirect.

= Can I recover from a mistake? =

Yes. `define( 'BASTION_DISABLED', true );` in `wp-config.php` stops every change in memory, without the plugin writing to the database. `wp bastion disable` does the same from a terminal.

= How do updates work? =

The plugin is published on GitHub rather than in the WordPress.org directory, so it brings its own update check: it reads its latest release from github.com and reports it on the Plugins and Updates screens, where "Update now" and automatic updates work exactly as they do for a directory plugin. The check runs every 12 hours at most and can be switched off in Settings → Bastion → Housekeeping.

= Which PHP versions are supported? =

PHP 8.2 to 8.5, and WordPress 6.5 to 7.1.

== Screenshots ==

1. The audit: every check, its status and the real evidence.
2. Overview: mode, master switch and last measurement.
3. Headers: CSP mode and policy, framing, disclosures.
4. Email: the SPF and DMARC records to publish.
5. The settings screen on a phone.

== Changelog ==

= 1.1.1 =
* The changelog in the "View details" modal is written in the plugin's own typography instead of raw Markdown: headings, bold, inline code and lists are converted, and everything is escaped.
* The self-updater is now covered by the test suite: version tags, asset selection, drafts and pre-releases, and the changelog conversion. 249 assertions.

= 1.1.0 =
* The redirect now covers wp-login.php, the password reset and the whole login flow — the file never fires template_redirect, so it gets its own hook.
* A non-standard port is kept in the redirect target: a site on 8443 is no longer sent to 443.
* New audit check: "Login screen is not served in clear text".
* The CSP safe preset is frame-ancestors and base-uri only; object-src 'none' and form-action 'self' moved to the strict preset, where breaking something is expected.
* Permissions-Policy no longer disables payment, which broke Apple Pay, Google Pay and Stripe express.
* X-Powered-By is removed even when the header switches are off: it is a disclosure, not a header policy.
* High-frequency log events are throttled to one entry per minute, so a dry run does not write to the options table on every page view.
* The welcome notice has a real Dismiss button instead of a client-side close that came back on the next page.
* Twenty-check audit. 226 assertions.

= 1.0.0 =
* First release.
* Audit mode by default: measures and reports, changes nothing.
* HTTPS redirect with dry run, status choice and trusted proxies.
* HSTS with max-age, includeSubDomains and preload.
* CSP report-only or enforcing, three presets, framing kept consistent.
* nosniff, Referrer-Policy, Permissions-Policy, COOP, X-XSS-Protection.
* SPF and DMARC records generated and verified through DNS.
* Generator, ?ver=, RSD, WLW, shortlink, X-Pingback, X-Powered-By and readme.html closed.
* Twenty-check audit with evidence, Markdown export, WP-CLI, activity log.
* 205 assertions.

== Upgrade Notice ==

= 1.1.1 =
The changelog in the update modal now renders as typography instead of raw Markdown. No behaviour change on the site itself.

= 1.1.0 =
The HTTPS redirect now covers the login screen, and keeps a non-standard port. Safer CSP preset, no payment permission removed, throttled log.

= 1.0.0 =
First release.
