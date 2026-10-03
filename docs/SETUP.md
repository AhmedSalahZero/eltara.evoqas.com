# Setting up El Tara on your computer (Windows)

Follow these steps once. Every command is typed in a terminal opened **inside the project folder**
(in File Explorer, open `D:\My Projects\el-tara`, click the address bar, type `cmd`, press Enter).

## 0. What you need installed

| Program | Check with | Needed |
|---|---|---|
| PHP | `php -v` | 8.4 (you have 8.4.16 ✔) |
| Composer | `composer -V` | 2.x |
| Node.js | `node -v` | **20.19 or newer** (22 LTS recommended) |
| MySQL | — | the `el_tara` database you already created ✔ |

If `node -v` shows something lower than 20.19, install the "LTS" version from https://nodejs.org first.

> The warning about **Imagick** when running PHP is harmless — El Tara uses GD for images.

## 1. Put the new files in place

1. Keep any older copy as a backup under another name (do not copy new files on top of it).
2. Unzip `el_tara.zip` and name the folder `D:\My Projects\el-tara`.

## 2. The settings file (.env)

```
copy .env.example .env
```

Open `.env` in Notepad and fill in:

| Line | What to write |
|---|---|
| `DB_PASSWORD=` | your MySQL password (leave empty if you have none) |
| `DEFAULT_PASSWORD=` | the password for your Super Admin and the demo accounts, e.g. `Tara@2026` (8+ characters with a capital, a number and a symbol) |
| `SUPER_ADMIN_EMAIL=` | your own email for signing in as Super Admin |

Never share or zip the `.env` file — it holds your passwords.

## 3. Install and prepare (copy these one by one)

```
composer install
npm install
php artisan key:generate
php artisan migrate
php artisan db:seed
npm run build
```

What each does: installs the PHP parts · installs the screen parts · creates the secret key · creates the tables · creates your Super Admin and the demo company · builds the screens.

## 4. Run it — with Laravel Herd

You use **Laravel Herd**, so there is no need for `php artisan serve`: Herd runs the site for you all the time.

1. The folder must be named **`el-tara`** (with a dash). Web addresses must not contain `_`, and secure
   certificates refuse it.
2. Herd → **Sites** → **+ Add** → **Link existing project** → choose `D:\My Projects\el-tara`.
3. Click the site → check **PHP Version 8.4** and Node 20.19 or newer.
4. Click the **padlock** (top right) so the address becomes **https://el-tara.test**.
   The padlock is required for the Driver App to work offline and to be installable.
5. In `.env` set `APP_URL=https://el-tara.test`, then run `php artisan optimize:clear`.
6. Open **https://el-tara.test** in Chrome.

### If Chrome says "Your connection is not private" (NET::ERR_CERT_AUTHORITY_INVALID)

Windows does not trust Herd's certificate yet. Do this once:

1. In File Explorer paste `%USERPROFILE%\.config\herd\config\valet\CA` in the address bar.
2. Double-click **`LaravelValetCASelfSigned.crt`** → **Install Certificate…** → **Current User** → **Next**.
3. **Place all certificates in the following store** → **Browse…** → **Trusted Root Certification Authorities** → OK → Next → Finish → **Yes**.
4. Close every Chrome window and open it again.

Never copy, share or import the `.key` file in that folder.

### Without Herd

`php artisan serve` and open http://localhost:8000 (keep the window open; Ctrl + C stops it).

## 5. Sign in and try it

All passwords are the `DEFAULT_PASSWORD` you chose.

| Who | Sign in with | Lands in |
|---|---|---|
| You — Super Admin | `SUPER_ADMIN_EMAIL` from .env | Platform overview |
| Ahmed — company admin | `ahmed@nile-transport.test` | Office (all permissions) |
| Mona — fleet manager | `mona@nile-transport.test` | Office (limited; approval limit EGP 1,000) |
| Karim — CFO | `karim@nile-transport.test` | Office (finance permissions) |
| Eng. Ashraf — client | `ashraf@sinai-marble.test` | Client portal |
| Mahmoud — driver | open https://el-tara.test/driver · mobile `01001234567` · PIN `1234` | Driver App |

Driver App address: **https://el-tara.test/driver**. Things to try are listed in [STEP_01_FOUNDATION.md](STEP_01_FOUNDATION.md#how-to-try-step-1-by-hand).

## 6. Run the automated tests

```
php artisan test
```

All tests should show ✓ (71 at the end of Step 1). They use a temporary database — your data is not touched.

## Emails while building

`.env` has `MAIL_MAILER=log`: emails (activation links, reset links) are written into
`storage\logs\laravel-<date>.log` instead of being sent. Open that file and copy the link from it.
To test your real mail settings later: `php artisan mail:diagnose you@example.com`.

## While changing screens

Instead of `npm run build` after every change, run `npm run dev` in a second terminal: screens update as files are saved.
The Driver App's offline mode only works after `npm run build` (the offline helper is not active in dev mode).

## If something goes wrong

| Message | Fix |
|---|---|
| `Vite manifest not found` | run `npm run build` |
| `SQLSTATE[HY000] [1045] Access denied` | wrong `DB_PASSWORD` in .env |
| `Set DEFAULT_PASSWORD in your .env file` | fill in `DEFAULT_PASSWORD`, then `php artisan db:seed` again |
| a change in .env seems ignored | `php artisan config:clear` |
| start again with an empty database | `php artisan migrate:fresh --seed` (**deletes all data**) |
