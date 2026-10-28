VaultGate — bundled typefaces
=============================

All three families are self-hosted. No request ever leaves the site for a font:
no Google Fonts, no CDN, no third-party tracker.

| File                    | Family          | Source of the file in this workspace | Licence        |
|-------------------------|-----------------|--------------------------------------|----------------|
| inter-latin.woff2       | Inter (variable) | atmis45/assets/fonts                 | SIL OFL 1.1    |
| inter-latin-ext.woff2   | Inter (variable) | atmis45/assets/fonts                 | SIL OFL 1.1    |
| spacegrotesk-latin.woff2| Space Grotesk (variable) | atmis45/assets/fonts        | SIL OFL 1.1    |
| spacegrotesk-latin-ext.woff2 | Space Grotesk (variable) | atmis45/assets/fonts    | SIL OFL 1.1    |
| jetbrainsmono-latin.woff2 | JetBrains Mono (variable) | Fortress/assets/fonts      | SIL OFL 1.1    |

Each file is the variable-weight build, so one file covers every weight the
interface uses (400 to 700). `latin-ext` is loaded only for characters outside
the Latin-1 range, thanks to `unicode-range`.

Where each family is used
-------------------------

- **Inter** — the visible front gate page (the SuperPhoto / SuperPrint
  typography), and every text run in the WordPress admin screen.
- **Space Grotesk** — headings only, in the admin screen.
- **JetBrains Mono** — micro-labels, codes and counters, in the admin screen.

Inter, Space Grotesk and JetBrains Mono are licensed under the SIL Open Font
License 1.1. The full licence text is in `OFL.txt`, next to this file.
