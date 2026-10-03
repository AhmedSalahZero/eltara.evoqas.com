# Step 1 — Foundation

What Step 1 delivers, how each part works, where to find its code and its test, and how to try it yourself.

## 1. Look and language (Scope §2)

- The approved demo's design, carried over exactly: `resources/css/eltara.css`. Dark and light themes, Arabic right-to-left.
- Arabic is the default; each person switches **ع / EN** and **sun / moon** in the top bar, saved on their own account.
- The **☰ button** in the top bar folds the sidebar to icons only (hover an icon to see its name) and opens it again;
  the choice is remembered on that computer. On a phone the same button opens the menu as a drawer.
- Numbers always use Western digits with thousands separators (`resources/js/Utils/format.js`).
- Every screen word is in `resources/js/lang/ar.js` and `en.js`; server messages and emails in `lang/ar` and `lang/en`.
- Tests: `PreferencesTest`, `TranslationsTest` (both languages must have the same words).

## 2. Signing in

| Who | How | Code |
|---|---|---|
| Office staff, Super Admin, client users | `/login` with email **or** mobile + password | `app/Http/Requests/Auth/LoginRequest.php` |
| Drivers | Driver App: mobile + 4-digit PIN | `app/Http/Requests/Driver/DriverLoginRequest.php` |

- No public registration. New accounts get an **activation email** to choose their own password (link valid 7 days).
- "Forgot password" works for office and client users (link valid 60 minutes).
- Protection: 5 wrong passwords → wait 1 minute; 5 wrong PINs → wait 15 minutes; mobile numbers typed any way are understood
  (Arabic digits, +20, spaces).
- Suspended user, suspended company or never-activated account → refused, with a clear message.
- Tests: `SignInTest`, `ActivationAndResetTest`, `DriverAppTest`.

## 3. Companies and limits — Super Admin (Scope §4)

Screens: **Platform overview** (`/admin`) and **Companies** (`/admin/companies`).

- **New company**: names (ar/en), its admin (name, mobile, email), office-users limit, driver-accounts limit,
  subscription dates, status (trial / active / suspended), default language and theme. Creating it also creates the
  admin account and emails the activation link. Defaults: 5 office users, 15 drivers, 30-day trial (`config/eltara.php`).
- **Edit limits**: a limit can never go below the accounts already active.
- Overview figures: active companies, office users and drivers against their limits, companies at a limit, subscriptions ending.
- The Super Admin sees counts, never a company's money.
- Code: `app/Http/Controllers/Admin/`, `app/Services/CompanyOnboarding.php`. Test: `SuperAdminCompaniesTest`.

## 4. Users and permissions — company admin (Scope §5)

Screen: **Users & permissions** (`/office/users`).

- Invite a user by email, within the office-users limit; optionally start with another user's permissions.
- Permission grid per named user: 16 features × View / Create / Edit / Approve / Delete, plus *Edit price* (trips) and
  *Re-open* (month close). Ticking any action also gives View. "Copy permissions from…" fills the grid from someone else.
- Whoever may approve wallet transfers must have an **approval limit** (the most they approve in one transfer).
- Suspend / reactivate (a suspended user frees a place; reactivating needs a free place). Nobody can suspend themselves;
  the company admin always has everything and cannot be limited.
- Resend activation / send a password reset. "Last sign-in" shown for each user.
- Every change is written to the audit log.
- Code: `app/Http/Controllers/Office/UserController.php`, `config/permissions.php`. Test: `UsersAndPermissionsTest`.

## 5. Subscription ending (Scope §4.2)

- From 30 days before the end: an amber banner in the office, and a weekly reminder email to the company admin
  (`subscriptions:notify-expiring`, daily).
- After the end date the company is **read-only**: everyone can sign in and see everything, but nothing can be saved
  (language, theme and own password still work). The Driver App keeps entries on the phone until renewal. Nothing is deleted.
- Code: `app/Http/Middleware/BlockWritesWhenReadOnly.php`. Test: `SubscriptionReadOnlyTest`.

## 6. Driver App base and offline sync (Scope §8, §12)

- Open `/driver` on a phone. The sign-in screen shows the El Tara warehouse picture (`public/images/driver-login-bg.jpg`)
  with the form in a panel at the top. To change the picture, replace that file (portrait, about 600×1000) and run `npm run build`.
- It can be installed on the home screen ("Install" in My account) and opens full screen.
- After the first sign-in it opens and works **with no signal**. A banner always shows online / offline and how many
  entries wait to upload; they upload by themselves when the signal returns (or with "Upload now").
- In Step 1 the offline path is proven with the driver's language/theme choice; trips, expenses and cash follow in Step 4.
- Signing out wipes the phone's El Tara data (with a warning if something has not uploaded).
- Code: `resources/js/driver/`, `resources/js/pwa/sw.js`, `app/Sync/`. Tests: `DriverAppTest`, `OfflineSyncTest`, `PwaAndSecurityTest`.
- How it works in detail: [ARCHITECTURE.md → Offline sync](ARCHITECTURE.md#offline-sync-driver-app).

## 7. Menus for what comes next

Every module of the scope already has its place in the menu (shown only to people with the permission). Until it is
built, it opens a "Coming in step N" page (`app/Http/Controllers/ComingSoonController.php`).

## How to try Step 1 by hand

Sign-ins are in [SETUP.md](SETUP.md#5-sign-in-and-try-it).

1. **Super Admin** → Platform overview → *New company*. Fill it in with your own second email as admin. Open
   `storage\logs\laravel-<date>.log`, copy the activation link, open it, choose a password, sign in as that admin.
2. As Super Admin → *Edit limits* on "Nile Heavy Transport" → try setting office users to 2 (refused: 3 are in use).
3. **Ahmed** → Users & permissions → click Mona → untick/tick, *Copy permissions from… Karim*, save. Tick
   *Wallet transfers → Approve* without an approval limit → refused.
4. Sign in as **Mona**: the menu shows only what she may use; `/office/users` is refused.
5. Ahmed → *New user* until the limit is reached → the button is disabled. Suspend someone → a place frees up.
6. Switch **ع / EN** and **light / dark**, sign out and in again → your choice is remembered.
7. **Driver App** at `/driver` (after `npm run build`): sign in as Mahmoud. In Chrome press F12 → *Network* → choose
   *Offline*. Switch language in the app → the banner shows "1 waiting to upload". Set *No throttling* again → it uploads.
8. As Super Admin set Nile Heavy Transport's end date to yesterday → Ahmed sees the red read-only banner and cannot save.
   Set it back afterwards.
9. `php artisan test` → all green.

## Open decisions (Scope §17)

The scope lists six assumptions still to confirm. Step 1 already follows #2, #3 and #5; please confirm or correct
all six before the steps that use them.

| # | Topic | Assumption used | Affects |
|---|---|---|---|
| 1 | Hired / subcontracted trucks | Supported: no custody, the owner's fee is the main cost, no G&A share | Steps 2–3, 6 |
| 2 | Driver accounts limit | Separate from the office-users limit, set by the Super Admin | ✅ built this way in Step 1 |
| 3 | Re-opening a closed month | Only with a special permission, recorded in the audit log | permission `month_close.reopen` exists; used in Step 6 |
| 4 | Automatic-transfer limit default | 1,000 EGP (the company can change it) | Steps 2–3 |
| 5 | Client portal users | Created by the client's account admin; not counted in the office-users limit | ✅ separate table in Step 1; screens in Step 5 |
| 6 | Trips spanning two months | Split km by trip hours in each month | Step 6 |
