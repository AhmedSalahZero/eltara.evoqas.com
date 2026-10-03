# El Tara / التارة — Documentation

Everything about how El Tara is built, in plain language. Start with **SETUP.md**.

| File | What it covers |
|---|---|
| [SETUP.md](SETUP.md) | Installing and running El Tara on your Windows computer, step by step |
| [CLEAN_START.md](CLEAN_START.md) | What was removed or kept from the old Massar copy, and why |
| [ARCHITECTURE.md](ARCHITECTURE.md) | How the pieces fit together: portals, sign-in doors, companies, offline sync, growing to 500 companies |
| [STEP_01_FOUNDATION.md](STEP_01_FOUNDATION.md) | Step 1 features, how each works, where its code and test are, and how to try it by hand |
| [STEP_02_MASTER_DATA.md](STEP_02_MASTER_DATA.md) | Step 2: vehicles, drivers, customers & rate cards, routes & budgets, company settings |
| [STEP_03_TRIPS_AND_WALLETS.md](STEP_03_TRIPS_AND_WALLETS.md) | Step 3: trips and their lifecycle, expenses, the three wallets, transfers & approvals, settlement |
| [STEP_04_DRIVER_APP.md](STEP_04_DRIVER_APP.md) | Step 4: the Driver App — trips, expenses with photos, cash, delivery, fully offline |
| [STEP_05_CLIENT_PORTAL.md](STEP_05_CLIENT_PORTAL.md) | Step 5: client portal, client requests, two-sided cash confirmation, ratings & complaints, the bell |
| [STEP_06_FUEL_FINANCE_MONTH_CLOSE.md](STEP_06_FUEL_FINANCE_MONTH_CLOSE.md) | Step 6: fuel log, driver advances, invoice numbers, month close & true profit |
| [STEP_07_DASHBOARD_REPORTS_NOTIFICATIONS_AUDIT.md](STEP_07_DASHBOARD_REPORTS_NOTIFICATIONS_AUDIT.md) | Step 7: dashboard, 12 reports with Excel/PDF, new notifications, audit log screen |

## Working rules for this project

1. Every feature is documented here, in its step file.
2. Every file starts with a comment explaining what it does and where it sits.
3. Every feature has an automated test in `tests/`. Run them all with `php artisan test`.

## Build road map (Scope §16)

| Step | Contents | Status |
|---|---|---|
| 1 | Sign-in, companies & limits, users & per-user permissions, language & theme, audit log, offline sync engine, Driver App base | ✅ Done |
| 2 | Vehicles, drivers, customers, routes & budgets, rate cards, company settings | ✅ Done |
| 3 | Trips, expenses, three wallets, transfers & approvals, settlement | ✅ Done |
| 4 | Driver App: trips, expenses with photos, cash, delivery — offline | ✅ Done |
| 5 | Client portal and client requests, two-sided cash confirmation | ✅ Done |
| 6 | Fuel, advances, invoice numbers, month close & true profit | ✅ Done |
| 7 | Dashboard, reports & exports, notifications, audit log screen | ✅ Done |
| 8 | Testing with real sample data, go-live | |
