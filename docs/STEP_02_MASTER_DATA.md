# Step 2 — Master data

<!-- El Tara — docs/STEP_02_MASTER_DATA.md — the Step 2 features, how each works, where its code and test are, and how to try it by hand. -->

Step 2 builds the records every trip will use in Step 3: the trucks, the drivers, the customers and their agreed prices, the routes with their standard budgets, and the company's business rules.

All screens are in the **Company Office** (`/office`). Every record belongs to one company, and nobody can see or open another company's records, not even by typing the address (tested for every screen).

## Installing this step on your computer

The update adds new tables but does not change or delete anything you already have.

```
php artisan migrate
php artisan db:seed
npm run build
php artisan optimize:clear
```

`db:seed` adds the Step 2 demo data to "Nile Heavy Transport" (Ahmed's company). Running it again does not create duplicates. Companies you created yourself (e.g. Naqla Transportation) start empty, but receive the default settings and the 10 standard expense categories automatically the first time a Step 2 screen opens.

---

## 1. Vehicles — `/office/vehicles` (Scope §6.7)

**What it does.**
- **List.** It shows plate, type & model, ownership, driver, status, odometer, standard km/L, and one badge each for the licence, insurance and technical inspection.
  - A badge is red when the document has expired, amber when it ends within 30 days, and grey (showing the date) when it is fine.
  - An amber note at the top lists every document that needs attention.
  - You can filter by: all / own / hired / maintenance, and search by plate, model, driver or owner.
- **New / edit form.** It has these fields:
  - plate numbers and Arabic letters;
  - type (trailer, jumbo, heavy truck, tanker, other), model, year and capacity;
  - ownership;
  - the usual driver, odometer and standard km/L;
  - status (available / in maintenance);
  - the three documents with their numbers and expiry dates.
- **Hired trucks.** They need the owner's name. They have no documents, odometer or km/L of their own. The form reminds you that a hired truck gets no custody, that the owner's fee is its main cost, and that it carries no share of G&A.
- **Vehicle file.** It shows the details, the documents panel, and four figure tiles (trips, revenue, direct profit, fuel economy). The tiles fill in automatically once trips (Step 3) and fuel (Step 6) exist.

**Rules.**
- A plate is unique inside a company. Two different companies may have the same plate.
- Arabic digits typed in the plate are saved as 0–9.
- A driver has one usual vehicle. Choosing him for a second truck frees the first.
- "In maintenance" removes the truck from new trips (Step 3). "On a trip" is worked out from the trips themselves.
- A vehicle can be deleted only while nothing uses it. Once it has trips, it should be kept (you can set it to maintenance).

**Permissions.** `vehicles.view / create / edit / delete`.

**Code.**
- `app/Http/Controllers/Office/VehicleController.php`
- `app/Models/Vehicle.php`
- `app/Support/DocumentExpiry.php` (the 30-day rule)
- `resources/js/Pages/Office/Vehicles/*`
- `resources/js/Components/{VehicleForm,Plate,DocBadge}.vue`

**Test.** `tests/Feature/VehiclesTest.php`

## 2. Drivers — `/office/drivers` (Scope §6.8)

**What it does.**
- **List.** It shows driver and mobile, vehicle, pay basis, driving-licence badge, and when he last synced the Driver App. You can filter by: all / licence to renew / suspended.
  - A meter shows how many of the company's driver accounts are used. El Tara's Super Admin sets the limit.
- **New driver.** You enter:
  - name, mobile (this is what he signs in with), and PIN;
  - usual vehicle;
  - driving licence number and expiry;
  - pay basis (fixed / fixed + trip allowance / per trip), base salary and join date.
- **The PIN.**
  - You may type the first PIN or leave it empty to generate one.
  - A window then shows the PIN **once**. El Tara stores it scrambled and can never show it again, so note it and give it to the driver with the app address.
  - **New PIN** (on the driver file) gives him a fresh one, and the old one stops working at once.
- **Driver file.**
  - It shows the details and the three wallet tiles (custody, collections, advances), which come alive with the first trip in Step 3.
  - It also has the Edit, New PIN, Suspend / Reactivate and Delete buttons.

**Rules.**
- A mobile number can have only one driver account in the whole of El Tara, because it is his sign-in.
- New drivers and reactivations need a free place under the driver-accounts limit. Suspending frees a place.
- A suspended driver is signed out of the app and cannot sign in.

**Permissions.** `drivers.view / create / edit / delete`.

**Code.**
- `app/Http/Controllers/Office/DriverController.php`
- `resources/js/Pages/Office/Drivers/*`
- `resources/js/Components/{DriverForm,PinNotice}.vue`

**Test.** `tests/Feature/DriversTest.php` (includes signing in to the Driver App with the new PIN).

## 3. Customers, rate cards and client-portal users — `/office/customers` (Scope §6.9)

**What it does.**
- **Layout.** It follows the demo: customers on the left, and the selected customer's details on the right.
- **Customer.** Each customer has:
  - Arabic and English name;
  - payment terms in days (0 = pays on delivery);
  - whether they sometimes pay the driver cash;
  - contact person, phone, email, address, tax number and notes.
- **Rate card.** It holds the agreed price per route, for the round trip. For each line it shows:
  - km, price and price per km;
  - the route's standard cost;
  - the **expected direct profit** (price − standard cost);
  - the **profit after G&A** (direct profit − route km × *G&A per km estimate*).
  - Click a line to change the price, or to remove it.
- **Client-portal users.**
  - **Invite** creates a portal user and emails an activation link, valid 7 days.
  - The first user of a customer becomes its account admin. From Step 5, that admin adds their own colleagues.
  - Office users can suspend or reactivate portal users, and resend the link.

**Rules.**
- One price per customer per route.
- **Every price change is written to the audit log** with the old and new price (Scope §12).
- The G&A estimate is set in Company settings. It is used until the first month is closed (Step 6); after that, the real rate of the last closed month is used.
- A customer who has portal users cannot be deleted; set it to not active instead.
- A portal user's email cannot already belong to any other El Tara account.

**Permissions.** `customers.view / create / edit / delete`. Rate lines and portal users need `customers.edit`.

**Code.**
- `app/Http/Controllers/Office/CustomerController.php`
- `app/Models/RateCard.php`
- `resources/js/Pages/Office/Customers/Index.vue`
- `resources/js/Components/CustomerForm.vue`
- The client activation link uses the `client_invites` broker in `config/auth.php`.

**Test.** `tests/Feature/CustomersAndRateCardsTest.php` (includes a full activation and first sign-in to the client portal).

## 4. Routes & budgets — `/office/routes` (Scope §6.6)

**What it does.**
- **Routes.** Each route has an origin and destination (Arabic and English), km for the round trip, and usual hours.
- **Standard budget.** Each route has one box per expense category, e.g. fuel 3,450, tolls 260.
- **List.** For each route it shows:
  - the standard total;
  - the cash road costs;
  - the suggested custody;
  - the cost per km;
  - how many customers have a price on it.
  - The form adds these up while you type.

**How the figures are worked out (Scope §10).**
- **Cash road costs** = each budget line × that category's cash share. For example, fuel is 40% cash (the rest goes on the company card), the hired-truck fee is 0%, and the others are 100%.
- **Suggested custody** = cash road costs × (1 + custody buffer %), rounded **up** to the next 500.
  - Example: Obour → Alexandria Port has a standard budget of 4,880 and cash costs of 2,810.
  - 2,810 × 1.05 = 2,950.5, so the suggested custody is **3,000**.
- In Step 3, an expense more than 15% above its standard will be flagged on the trip.

**Rules.**
- The standard budget belongs to the route, the same for all customers.
- A route that customers have prices on cannot be deleted; hide it instead ("In use" off).

**Permissions — a decision.** The scope's permission list (§5) has no "Routes" line. Routes therefore follow **Customers & rate cards** (`customers.view / create / edit / delete`), because rate cards are built on routes. If you would rather have a separate "Routes & budgets" permission, it is a small change.

**Code.**
- `app/Http/Controllers/Office/RouteController.php`
- `app/Models/{TripRoute,TripRouteBudget}.php`
- `resources/js/Pages/Office/Routes/Index.vue`
- The table is called `trip_routes`, because "routes" means web addresses inside Laravel.

**Test.** `tests/Feature/RoutesAndBudgetsTest.php`

## 5. Company settings — `/office/settings` (Scope §6.15)

The demo's four panels:

| Panel | Settings (default) |
|---|---|
| Wallets & approvals | default transfer policy (*automatic up to a limit*), automatic-transfer limit (1,000 EGP), custody buffer (5%), personal spend → advance (on). The rule "a trip cannot close before settlement and proof of delivery" is shown as a mandatory rule. |
| True profit & month close | two-month rule (*split by hours*), G&A basis (*own-fleet km only*), G&A per km estimate (empty) |
| Driver app | receipt photo required, capture location, work offline (all on), max time without sync before an alert (12 hours) |
| General | default language & theme (for new users of the company), diesel price (20.50), currency (EGP), **expense categories** |

**Expense categories.**
- Every company starts with the 10 standard categories of Scope §6.6: fuel, road tolls, weigh station, driver trip allowance, loading & unloading labour, overnight & waiting, fines, road repair, hired-truck fee, and other.
- Click a category to rename it, change its cash share, or hide it.
- **+ Category** adds your own. Standard categories cannot be deleted, only hidden.

**Rules.**
- These are the company's defaults. In Step 3 a single trip can use a different transfer policy.
- Every change is audited with before and after values.
- People with only `settings.view` see the page greyed out.
- When the subscription has ended (read-only mode), the page can be seen but not saved.

**Code.**
- `app/Http/Controllers/Office/SettingsController.php`
- `app/Models/{CompanySetting,ExpenseCategory}.php`
- `app/Services/CompanyDefaults.php` (what every company starts with)
- `resources/js/Pages/Office/Settings/Index.vue`

**Test.** `tests/Feature/CompanySettingsTest.php`

## Also in this step

- **Office home.** It now marks Step 2 as done and shows a "Documents needing attention" note (vehicle documents and driving licences within 30 days) with links.
- **Menu.** Vehicles, Drivers, Customers & rate cards, **Routes & budgets** (new) and Company settings now open real screens.
- **Security.** In `bootstrap/app.php`, the portal gates now run before a record is looked up from its address. This is a second lock: the company was already known at that moment, and the "another company's record is not found" tests pass either way.

## Fixes made during Step 2 testing

- **Driver App sign-out crash.** Signing out showed *"Cannot read properties of null (reading 'driver')"* and a blank screen. For a moment the old screen was still drawn after the driver's details were cleared.
  - Fix: screens that need a signed-in driver are drawn only while one is signed in.
  - File: `resources/js/driver/App.vue`.
- **Driver App did not open offline.** The offline helper (`sw.js`) lists every file it saves on the phone, and one entry, `manifest.webmanifest`, was listed at the site root, where it did not exist. One missing file makes the browser reject the whole helper, so nothing was saved and an offline reload showed the browser's "no internet" page.
  - Fix: the manifest is now also served at `/manifest.webmanifest` (`PwaController::manifest`, `routes/web.php`).
  - New test: `PwaAndSecurityTest::test_every_file_the_offline_helper_lists_can_be_downloaded` checks that every listed file downloads.
  - **To check by hand:**
    1. Sign in to the Driver App.
    2. In DevTools, open Network and set "No throttling" to "Offline".
    3. Press F5. The app opens and shows *"No signal — saving on the phone"*.

## Try it by hand

Sign in as `ahmed@nile-transport.test` (password: your DEFAULT_PASSWORD).

1. **Vehicles.**
   - The amber note lists the documents that expire soon.
   - Open a truck, then use Edit to set it to *Maintenance*.
   - Add a hired truck and see the owner fields.
2. **Drivers.**
   - Click **New driver** and leave the PIN empty: the PIN window appears once.
   - Open `https://el-tara.test/driver` in an Incognito window and sign in with that mobile and PIN.
   - Try **New PIN**; the old PIN stops working.
   - The demo drivers' PIN is `1234`.
3. **Customers.**
   - Pick *Delta Steel*: its rate card shows price, cost budget, expected profit and profit after G&A.
   - Click a line and change the price, then check that the change is recorded.
   - Click **Invite** under client-portal users. The activation link is written to `storage\logs\laravel-<date>.log` (search `/activate/`); open it in Incognito.
4. **Routes & budgets.**
   - Open *Obour → Alexandria Port* and change the fuel budget; watch the totals and the suggested custody update.
5. **Company settings.**
   - Change the custody buffer to 10% and save.
   - Go back to Routes: the suggested custody has changed.
   - Click the *Fuel* category and change its cash share.
