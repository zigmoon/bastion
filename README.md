<p align="center">
  <img src="assets/icon-256.png" alt="Bastion" width="88" height="88">
</p>

<h1 align="center">Bastion</h1>

<p align="center">
  <b>HTTPS, security headers, a domain that cannot be spoofed — and a report that proves it.</b><br>
  A WordPress hardening plugin that changes nothing until you ask, and measures the live site when it does.
</p>

<p align="center">
  <a href="https://github.com/zigmoon/bastion/releases/latest"><b>⬇ Download</b></a> ·
  <a href="#install">Install</a> ·
  <a href="#the-screen">Screens</a> ·
  <a href="#why-it-cannot-break-the-site">Safety</a> ·
  <a href="#credits">Credits</a>
</p>

<p align="center">
  <img alt="WordPress 6.5 to 7.1" src="https://img.shields.io/badge/WordPress-6.5%20%E2%86%92%207.1-0b0d10?style=flat-square">
  <img alt="PHP 8.2 to 8.5" src="https://img.shields.io/badge/PHP-8.2%20%E2%86%92%208.5-0b0d10?style=flat-square">
  <img alt="Version 1.1.4" src="https://img.shields.io/badge/version-1.1.4-0a66ff?style=flat-square">
  <img alt="270 assertions" src="https://img.shields.io/badge/tests-270%20assertions-0b0d10?style=flat-square">
  <img alt="Licence GPL-2.0-or-later" src="https://img.shields.io/badge/licence-GPL--2.0--or--later-0b0d10?style=flat-square">
</p>

![The audit: every check, its status, and the real evidence](docs/audit-table.png)

Every row is a measurement, not a setting. Bastion fetches the site's own pages, reads the headers the server actually returned, then queries DNS — and prints what it found. A check that cannot be measured says so; it never turns green by default.

| Local test install | Passed | Failed | To review |
|---|---|---|---|
| Fresh install, audit mode | 0 | 15 | 3 |
| After the switches are applied | **9** | 5 | 4 |

The five remaining failures are honest for a sandbox: no certificate (no TLS, no HSTS), no DNS for `127.0.0.1` (no SPF, no DMARC), and PHP's built-in server serves `readme.html` from disk without calling WordPress.

## What it covers

| Area | What you get | Default |
|---|---|---|
| **HTTPS** | Redirect clear text to TLS, status code of your choice, trusted reverse-proxy list, and a **dry run** that logs instead of redirecting. Only `GET` and `HEAD` are redirected — a 301 on a POST turns it into a GET and loses the form. The login screen is covered too — `wp-login.php` never fires `template_redirect`, so it gets its own hook — and a non-standard port is kept. | off |
| **HSTS** | `Strict-Transport-Security` with your max-age, `includeSubDomains` and `preload` as separate choices. | off |
| **Headers** | CSP (report-only), `X-Frame-Options` kept consistent with the CSP `frame-ancestors`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, `X-XSS-Protection: 0`. | on, in apply mode |
| **Email** | The SPF and DMARC TXT records for your domain, written for the provider your MX points at, then read back through a real DNS query. | — |
| **Disclosures** | No generator meta, no `?ver=<core version>`, no RSD or WLW link, no shortlink, no `X-Pingback`, no `X-Powered-By`, 404 for `readme.html`. | on |

### The CSP preset matters more than the switches

| Preset | Policy | Can it break a page? |
|---|---|---|
| **Safe** (default) | `frame-ancestors`, `base-uri` | **No.** It says nothing about scripts, styles, plugins or forms. |
| **Strict** | `default-src 'self'` and down, including `object-src 'none'` and `form-action 'self'` | Yes — every inline script and style. Keep it report-only until the console is clean. |
| **Custom** | Your own line | Depends on what you write. |

`Safe` leaves out `default-src`, which is the fallback for `script-src`, and `object-src 'none'`, which stops a PDF from loading. A report-only CSP is reported **to review**, never as a pass.

## Install

1. Download `Bastion-1.1.4.zip` from [Releases](https://github.com/zigmoon/bastion/releases).
2. **Plugins → Add New → Upload Plugin**, install, activate.
3. **Settings → Bastion** → press **Run the audit**, read the report.
4. Fix what you can at the server level first. Then switch to **Apply** and enable one group at a time.

## The screen

| Overview | Headers | Email |
|---|---|---|
| [![Overview: mode, master switch, last measurement](docs/admin-overview.png)](docs/admin-overview.png) | [![Headers: CSP mode and policy, framing, disclosures](docs/admin-headers.png)](docs/admin-headers.png) | [![Email: the SPF and DMARC records to publish](docs/admin-email.png)](docs/admin-email.png) |

<img src="docs/admin-mobile.png" alt="The settings screen on a phone" width="260">

Five tabs — **Overview**, **HTTPS**, **Headers**, **Email**, **Audit**. Every boolean is a real switch: native input, keyboard-navigable, `role="switch"`. A generated DNS record copies with one click.

## Why it cannot break the site

A hardening plugin that acts the moment it is activated is a liability. Five decisions follow:

1. **Audit mode is the default.** Installed, it measures and reports and changes nothing. Every feature asks one gate — `applies()` — so "audit" really means read-only.
2. **The switches default to off**, redirect and HSTS included. The only thing on by default is the audit.
3. **The redirect has a dry run**, on by default, that logs instead of redirecting.
4. **No file is ever written.** No `.htaccess`, no `wp-config.php`, no theme edit, no table. Everything is a header, a redirect or a filter.
5. **`define( 'BASTION_DISABLED', true );`** in `wp-config.php` stops everything in memory, without the plugin reading the database to recover.

There is also `wp bastion disable` and `wp bastion audit-only` for a terminal.

## What it cannot do

- **Write DNS.** SPF and DMARC are TXT records at your registrar. Bastion writes them and reads back what is published; you paste them.
- **Generate DKIM.** Enable it in the mail provider and keep the key it gives you.
- **Delete a static file safely.** The `readme.html` block only works where the request reaches WordPress; if the web server serves the file directly, delete or block it in the server configuration. The audit tells you which case you are in.
- **Undo HSTS quickly.** Start short, confirm the site is stable, then raise it.
- **Replace a server-level redirect.** When both are configured the server answers first and Bastion never sees the request.
- **Retrofit CSP for you.** The safe policy cannot break a page because it ignores scripts; a strict one needs its violations read first.

## Compatibility and updates

| | |
|---|---|
| WordPress | 6.5 → 7.1 (latest stable at the time of writing: 7.1.4) |
| PHP | 8.2 → 8.5 |
| Database | No table; `wp_options` and two transients |
| Multisite | Yes, settings per site |
| PHP extensions | None beyond a standard install (`dns_get_record` or `checkdnsrr` for the DNS checks) |
| CLI | `wp bastion audit`, `settings`, `enable`, `disable`, `audit-only` |
| Outbound | One request to `github.com` for the update check — switchable off |

Not in the WordPress.org directory, so it brings its own update check: the `Update URI` header WordPress 5.8 added for this, plus the filter core then calls. New versions appear on **Plugins** and **Updates** and install normally, automatic updates included. The check runs at most every 12 hours, and **Settings → Bastion → Housekeeping** turns it off.

## Development

```bash
php tests/run.php                 # settings, headers, redirects, DNS, audit
php tests/smoke.php               # boot, hooks, admin render
php tools/build-translations.php  # .pot + fr_FR + es_ES, compiled and verified
pwsh -File tools/build-zip.ps1    # dist/Bastion-<version>.zip, hash-verified
pwsh -File tools/release.ps1 -Bump patch   # bump, test, ZIP, tag, push, release
```

270 assertions across the two suites, no WordPress installation needed. Tested on WordPress 7.1.4 and on PHP 8.2, 8.3, 8.4 and 8.5 with `E_ALL` — no notice, no deprecation. English source strings with complete French and Spanish catalogues.

## Credits

Bastion is a plugin by [2.13](https://2points13.fr/), dev by [zigmoon](https://zigmoon.com/). Licence GPL-2.0-or-later.