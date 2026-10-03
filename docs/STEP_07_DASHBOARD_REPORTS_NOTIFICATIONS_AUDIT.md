# Step 7 — Dashboard, reports & exports, notifications, audit log screen

<!-- El Tara — docs/STEP_07_DASHBOARD_REPORTS_NOTIFICATIONS_AUDIT.md — how the Dashboard, the 12 Reports with Excel/PDF export, the extra notifications and the Audit log screen work; where each piece of code and test lives; how to try it by hand. -->

The office start page is now the real **dashboard**, laid out exactly like the demo (Scope §6.1); **Reports** (§6.14) is a real menu item with 12 reports; the **bell** has a full "all notifications" page and four new office alerts (§11); and the **audit log** (§12) has its own screen for the company admin.

## Installing this step

No new tables. Nothing existing is deleted.

```
npm run build
php artisan optimize:clear
php artisan test
```

**Scheduler.** Two new commands run from `routes/console.php` (they need the usual one-line cron / `php artisan schedule:run`): `documents:notify-expiring` (daily 07:30) and `drivers:notify-unsynced` (hourly).

**Permissions.** `dashboard.view` shows the dashboard (a user without it still gets the simple start page); `reports.view` shows Reports. The dashboard's print page uses `dashboard.view`. The **audit log is for the company admin only** — not a permission that can be ticked.

---

## 1. Dashboard (`/office`, `Office/Dashboard.vue`)

Same order as the demo: period chips (This month · Last month · 3 months · Year) → two rows of KPI tiles → **On the road right now** → 12-month chart + **cash outside the safe** → action centre · cost mix · fleet → trip pipeline → customers · routes · trucks → driver scorecard · trips that need a look → budget vs actual · month close.

* Every figure is built by `app/Services/Dashboard/DashboardService.php` from the real trips — the same rules as the Month close screen, so the numbers agree.
* **Revenue / cost / km** count the trips **delivered** in the period. **True profit** = direct profit − G&A. A closed month uses its saved G&A; an open month uses km × the last closed month's rate and is marked **Estimate until month close**. Before any month is closed and no estimate is set in Settings, true profit shows "—" instead of guessing.
* **Fleet utilisation** = trip hours ÷ available hours of the own fleet (trucks in maintenance are not available).
* **On the road:** the truck's place on the road is an **estimate from the time since departure** (no live GPS — location is captured only at key moments, as the Scope says). The screen says so.
* **Cash outside the safe** = custody + collections + advances currently in drivers' hands.
* **Action centre** (10 lines, each opens its screen; lines you have no permission for are hidden): new requests · unconfirmed collections · transfers waiting · automatic transfers to review · delivered trips to settle · loss-making trips · trips over budget · documents expiring · abnormal fuel · settled trips with no invoice (3+ days).
* **Export** opens a printable page (`/office/dashboard/print`) — *Print, then Save as PDF*, the same method as the client statement.
* Code: `DashboardService`, `Office\HomeController` (decides dashboard vs simple page), `Office\DashboardController@print`, `resources/views/office/dashboard-print.blade.php`, components `Spark`, `ComboChart`, `Donut`, `Gauge`. Test: `tests/Feature/DashboardTest.php`.

## 2. Reports (`/office/reports`)

A hub of 12 cards, each opening a report with filters (period · customer · truck · driver), **Excel** and **PDF** buttons:

| Report | Shows |
|---|---|
| Trip profitability | each delivered trip: revenue, direct cost, profit, margin, per km, G&A share, true profit (estimates marked) |
| Vehicle profitability | per truck (hired trucks grouped): revenue, cost, profit, G&A, true profit per km |
| Driver performance | trips, km, profit, budget variance, cash held now, advances owed |
| Customer profitability | revenue, cost, profit, margin, average per trip |
| Route profitability | profit per km and budget variance, best first |
| Driver wallet statement | every wallet movement; with one driver chosen, a running balance |
| Wallet transfer log | who asked, who decided, when, policy, status |
| Month close report | closed months: G&A, rate, true profit, who closed |
| Fuel analysis | litres, cost, km/L vs standard per truck, flagged ones |
| Expiring documents | truck papers and driving licences expired or ending within 30 days of the period end |
| Trips without an invoice | settled trips still without an invoice number |
| Budget vs actual | standard route budgets vs real spending per category |

* One class builds every report (`app/Services/Reports/ReportService.php`), so the screen, the Excel file and the printed page always show the same numbers. The screen shows the first 500 rows; the files have all (up to 5,000).
* Every export is written to the audit log (`report.exported`).
* Column and report names: `lang/en/reports.php`, `lang/ar/reports.php`. Code: `Office\ReportController`, `Pages/Office/Reports/{Index,Show}.vue`, `resources/views/office/report-print.blade.php`. Test: `tests/Feature/ReportsTest.php`.

## 3. Notifications (Scope §11)

| Event | Who | Status |
|---|---|---|
| New client request | office | Step 5 |
| Request approved / declined | client | Step 5 |
| Cash recorded by one side | the other side | Step 5 |
| Complaint submitted / answered | office / client | Step 5 |
| **Transfer needs approval** | office users who may approve **within their limit** | **new** |
| **Delivery confirmed** | client (Step 5) **and office users who may settle** | **new** |
| **Document expiring** (30 / 14 / 7 / 3 / 1 / 0 days) | office users who see trucks / drivers | **new** — daily job |
| **Driver app not synced** beyond the company's set hours (Settings), driver on the road | office users who see drivers | **new** — hourly job, once per silence |
| Trip assigned · transfer decided · trip settled · document expiring | **driver** | shown inside the Driver App itself (built from its own phone data, so it works offline) |

* The bell now ends with **"See all notifications"** → a full page for the office *and* the client portal (all / unread only, mark all read).
* Notifications can never break the action behind them: a failure is only logged.
* Code: `Services/Notifier.php` (`toOfficeWhere`), `Console/Commands/NotifyExpiringDocuments.php`, `NotifyUnsyncedDrivers.php`, `NotificationController@index`, `Pages/Notifications/Index.vue`, `Utils/notify.js`. Test: `tests/Feature/NotificationsStep7Test.php`.

## 4. Audit log screen (`/office/audit`) — company admin only

* Who did what and when: every approval, settlement, price change, month close / re-open, permission change, report export and more. Read-only — rows can never be edited or deleted.
* Filters: period · person · area (trips, wallets…) · word search. Click a row to see what changed (**before → after**). **Excel** export of the same filter.
* Only the company's own rows are shown. Anyone else — even an office user with every permission — gets "forbidden", and the menu item is hidden.
* Code: `Office\AuditController`, `Pages/Office/Audit/Index.vue`, `lang/*/audit.php` (action names). Test: `tests/Feature/AuditScreenTest.php`.

## How to try it by hand

1. Sign in as the company admin → the dashboard opens. Click the period chips; hover the chart; click a truck on the road board (opens the trip).
2. *Reports* → open *Trip profitability* → change the dates → *Excel* and *PDF*.
3. Ask for a wallet transfer on a trip whose policy is "approval always" → the bell of the approver shows it.
4. *php artisan documents:notify-expiring* (a truck document ending in 7 days) → a bell line appears.
5. *Audit log* → find the report export you just made.

## Known limits

* The truck position on the road board is an estimate from elapsed time.
* PDF means *Print, then Save as PDF* (no PDF library added).
* Reports show money in EGP; the report row limit is 5,000.
