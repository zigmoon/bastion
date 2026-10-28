# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x | Yes |

## Reporting a vulnerability

Please **do not** open a public issue for a security problem.

Use GitHub's private security advisories:

<https://github.com/zigmoon/bastion/security/advisories/new>

A useful report includes:

- the Bastion, WordPress and PHP versions;
- a description of the problem and its impact;
- reproduction steps, ideally with the response headers or a request;
- any mitigation you already know of.

Acknowledgment within 72 hours. A fix and a coordinated release follow as soon as possible.

## Scope

In scope:

- disabling the site, the dashboard or the login by a header, a redirect or a rewrite;
- an open redirect through the HTTPS redirect, or a redirect loop;
- header injection through any setting (a newline that reaches `header()`);
- trusting a forwarded scheme from a peer that is not on the declared proxy list;
- weakening the site's security silently — a header that claims more than it does;
- reading a value the current user may not read through the audit or the report;
- a check that reports a pass it did not measure.

Out of scope:

- anything that requires an already-compromised administrator account;
- the DNS records themselves: a plugin cannot publish them, and a wrong record is a registrar problem;
- the behaviour of the web server, the CDN or the hosting provider;
- WordPress core and PHP themselves.

## What the plugin never does

Bastion writes no file: no `.htaccess`, no `wp-config.php`, no theme or plugin
edit. It creates no table and stores no per-request data beyond a capped log
that holds no address. It runs no SQL of its own. Its only outbound request is
the update check against `github.com`, which can be switched off. Everything it
does is a response header, a redirect, or a filter, and `BASTION_DISABLED`
undoes all of it in memory.
