# Clean start — what happened to the Massar copy

El Tara was started from a copy of the older **Massar** project, as agreed. The copy was cleaned, not reused as it was.

## Problems found in the copy

- **Broken references:** the routes pointed to about 20 controllers that did not exist in the copy.
- **Private data:** `storage/` held about 580 encrypted CV files and log files. All were deleted.
- **Secrets:** the real `.env` file (with passwords and the app key) was inside the zip. It was deleted.
  **If that zip was ever sent to anyone, change those passwords.**
- **Missing pieces:** Massar had removed Tailwind CSS and its PWA; El Tara needs both, so both were added back.

## Kept (renamed and rebranded)

| Kept | Why |
|---|---|
| Laravel 12, Inertia 2, Vue 3, Ziggy, the same `composer.lock` package versions | proven, up to date |
| `EgyptPhone` (mobile numbers typed any way → one form) | used for sign-in by mobile |
| `PasswordRules` + `ContainsUppercaseLetter` | same password strength rules |
| `ArabicShaper` | correct Arabic letters in PDF exports (Step 7) |
| `NoStoreForAuthenticated` middleware | stops browsers storing signed-in pages (see the lesson below) |
| `PreventDuplicateSubmission` middleware | ignores a form sent twice in a few seconds |
| `mail:diagnose` command, email layout styles | useful as they were |
| dompdf, PhpSpreadsheet | PDF and Excel exports later |

## Removed

Everything specific to the old project: CV/recruitment tables, models, screens, routes, jobs, seeders, email texts, the old
"partners" model and subscription config, old tests, uploaded files and logs, and the old readme.

## Lesson carried over

On Massar, an offline helper once kept one company's pages and showed them to another company.
El Tara's offline helper (`resources/js/pwa/sw.js`) **never** keeps office, admin or client pages — only
the app's files and the Driver App's empty shell page, which contains no personal data. Tests check this.
