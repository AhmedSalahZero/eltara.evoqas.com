# El Tara — How it is built

## The pieces

| Layer | Technology | Where |
|---|---|---|
| Server | Laravel 12 on PHP 8.4 | `app/`, `routes/`, `config/` |
| Database | MySQL 8 (utf8mb4 — Arabic safe) | `database/migrations/` |
| Office, Super Admin and client screens | Vue 3 + Inertia 2 (pages without a separate API) | `resources/js/Pages/` |
| Driver App | Vue 3 + Vue Router, a separate installable app (PWA) | `resources/js/driver/` |
| Styling | Tailwind CSS 4 + the approved demo's design system | `resources/css/` |
| Offline | service worker (Workbox) + IndexedDB on the phone (Dexie) | `resources/js/pwa/`, `resources/js/driver/db.js` |
| Builder | Vite 7 | `vite.config.js` |

Fonts (Tajawal, Inter) are bundled with the app — no call to Google — so they also load offline.

## Four portals, three sign-in doors

| Portal | Address | Who | Sign-in door (guard) | Accounts table |
|---|---|---|---|---|
| Platform | `/admin` | Super Admin (you) | `web` | `users` (role `super_admin`) |
| Company office | `/office` | company admin + office users | `web` | `users` |
| Client portal | `/client` | the company's clients | `client` | `client_users` |
| Driver App | `/driver` | drivers | `driver` (mobile + PIN) | `drivers` |

Office users and client users sign in on the same page (`/login`); El Tara finds which kind of account it is.
One email or mobile can only open one account across both, so this is never ambiguous.

Each portal's pages pass a gate (`app/Http/Middleware/Ensure…Access.php`) that also checks, on **every** request,
that the person and their company are still active — suspending a company signs everyone out at once.

## One database, many companies

All companies share one database; every company-owned row carries `company_id`.

- `App\Support\Tenant` holds "the company of this request", set by the portal gate.
- Models using `App\Support\Concerns\BelongsToCompany` are **filtered automatically** to that company, and new
  rows are stamped with it. A developer cannot forget the filter.
- With no company set during a request, the filter matches nothing (`Tenant::ORPHAN`) — it fails closed.
- Test: `tests/Feature/TenantIsolationTest.php`.

## Permissions

- Per named user, not per role (Scope §5). The list of 16 features and their actions is in `config/permissions.php`.
- Stored on the user as a list of keys like `trips.edit`, checked with Laravel's `can('trips.edit')` everywhere:
  routes, controllers and (to hide buttons) the screens. The server check is the one that protects.
- The company admin always passes every check. The Super Admin has no company permissions — only the platform.
- Remembered for 10 minutes per user and forgotten immediately when changed (`App\Support\Permissions::flush`).

## Audit log

`App\Support\Audit::record('action', $subject, $changes)` writes who did what, when, from where, with before/after values
into `audit_logs`. Rows can never be edited or deleted (`App\Models\AuditLog`). The screen to browse it comes in Step 7.

## Money: one set of rules, one ledger (Step 3)

- All trip and wallet rules live in `app/Services/Trips/*`: creating a trip, its steps, expenses, cash from the
  client, transfers and approvals, settlement. The office screens (Step 3) and the Driver App (Step 4) call the
  same services, so the rules can never differ between the two. Who acted is passed in as an `Actor`
  (office user, driver or system).
- A refused rule throws `TripRuleException` with a message for the person; office screens show it in red,
  the Driver App will show it as a refused offline entry.
- Every movement of a driver's money is one row of `wallet_entries` (the ledger), written only by
  `WalletLedger`. Balances are sums of rows. Rows are never changed or deleted — a correction is a new row.
- Tests: `tests/Feature/{TripsTest,WalletsTest,SettlementTest}.php`.

## Offline sync (Driver App)

```
 phone (no signal)                         server
 ─────────────────                         ──────
 record(type, data)  → outbox (IndexedDB)
      … signal returns …
 syncNow() ── batch of ≤ 50, in order ──→  POST /driver/api/sync
                                           SyncProcessor: for each entry
                                             receipt exists?  → duplicate
                                             unknown type     → rejected
                                             data invalid     → rejected
                                             handler refuses  → rejected
                                             else record + receipt in ONE transaction → applied
 delete applied/duplicate ←── results ──
 keep rejected, show reason
```

- Each entry has a uuid made on the phone. `sync_receipts.uuid` is unique, so an entry sent twice (lost answer,
  double tap, two tabs) is recorded **once**.
- Kinds of entries are registered in `config/sync.php`; each has a handler in `app/Sync/Handlers/`.
  Step 1 has `driver.preferences`; Steps 3–4 add expenses, cash, delivery, etc.
- Subscription ended → the upload is refused (423) and the entries simply wait on the phone.
- Receipts older than 90 days are deleted daily (`sync:prune-receipts`).

## What the phone keeps (and never keeps)

| Kept offline | Never kept |
|---|---|
| App files (JS, CSS, fonts, icons) | Office, admin, client pages |
| The Driver App's empty shell page | Any `/driver/api` answer in the browser cache |
| The driver's own profile and outbox in IndexedDB — wiped on sign-out | Other drivers' data |

Signed-in web pages are also sent with `Cache-Control: no-store` (`NoStoreForAuthenticated`).

## Scheduled tasks

`routes/console.php`: subscription reminders (07:00) and receipt clean-up (03:30). They need one cron line on the server:
`* * * * * cd /path/to/el_tara && php artisan schedule:run`.

## Growing to 500 companies, 20,000 users

The code is ready for it; the live server needs these settings (not needed on your computer):

1. **Redis** for sessions, cache and queues: `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`.
2. **A queue worker** always running (`php artisan queue:work`, kept alive by Supervisor) — emails then go in the background.
3. **Indexes** — every company table is indexed by `company_id` first; each later step adds indexes for its lists.
4. **Lists are paged** (25 per page) and counts use grouped queries (e.g. 500 companies = 1 query on the platform overview).
5. **Photos** (Step 4) go to private file storage (`trip_files` disk), switchable to S3-compatible storage.
6. **Backups** — nightly database + files, e.g. with `spatie/laravel-backup` (to be added before go-live, Step 8).
7. **HTTPS** is required on the live site — phones only allow offline apps on secure sites.
