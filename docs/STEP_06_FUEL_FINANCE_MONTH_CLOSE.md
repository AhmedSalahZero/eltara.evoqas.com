# Step 6 — Fuel, driver advances, invoice numbers, month close & true profit

<!-- El Tara — docs/STEP_06_FUEL_FINANCE_MONTH_CLOSE.md — how the Fuel log, Driver advances, Invoice numbers and Month close screens work; the rules behind true profit; where each piece of code and test lives; how to try it by hand. -->

Four finance screens are now real (they were "coming soon"), and the trip's **true profit** is finally computed from real month-end figures (Scope §6.10–§6.13, §10):

| Menu item | What it does |
|---|---|
| **Fuel log** | Every refuel, km per litre of each truck (tank to tank), flags a truck that drinks more than its standard, and lists fuel the drivers paid on trips that still has no litres. |
| **Driver advances** | What drivers owe; monthly instalments; the payroll deduction (once per advance per month) with an Excel sheet for the accountant; cash repayments. |
| **Invoice numbers** | Record the ERP invoice number against the settled trips it covers; see which settled trips still have no invoice. |
| **Month close & true profit** | Enter the month's G&A, share it over the trips by km, lock the month; every trip's true profit becomes final. |

## Installing this step

Adds tables `invoices`, `fuel_entries`, `driver_advance_repayments`, `ga_entries`, `month_closes`, `trip_allocations`, a column `trips.invoice_id` and a setting `company_settings.fuel_flag_percent` (7). Nothing existing is changed or deleted.

```
php artisan migrate
php artisan db:seed          # optional: demo fuel, advances, invoices and last month's G&A
npm run build
php artisan optimize:clear
php artisan test
```

**Permissions.** The keys already existed (`fuel`, `driver_advances`, `invoice_links`, `month_close`). The company admin sees everything. Other users need them ticked in *Users* (the seeder adds them for the two demo users, Mona and Karim).

---

## 1. Fuel log (`/office/fuel`)

* Month picker, tiles (litres · cost · fleet km/L · share on the company card · trucks flagged) and three tabs: **Refuels · Per truck · Waiting for litres**.
* **A refuel on a trip is the same money as the trip's fuel expense — counted once.**
  * If the driver already recorded the expense, open *Waiting for litres*, press *Add litres* and the litres/odometer are attached to that expense. Nothing is added to the trip's cost.
  * If not, recording the refuel creates the expense (company card → paid from company; custody → paid from the driver's custody).
  * A refuel **without a trip** is fuel data only (company card only).
  * Deleting a refuel never deletes the expense.
* Type any two of litres / price / amount; the third is worked out (litres from the amount use the diesel price from Settings and are marked *estimated*).
* **km per litre** = km between two fill-ups ÷ litres of the later fill. The first fill, or one without an odometer, shows no figure; an odometer that goes down is marked *bad*.
* **Flag** = more than *N*% below the truck's standard km/L. *N* is **Settings → Fuel flag %** (7 by default).
* Hired trucks have no fuel log (their fuel is inside the hire fee).
* Code: `Office\FuelController`, `Fuel\{FuelService, FuelAnalysis, FuelMath}`, `Pages/Office/Fuel/Index.vue`. The truck page shows this month's km/L against the standard.

## 2. Driver advances (`/office/advances`)

* Tabs **Open · Payroll deductions · Closed**; tiles for what is owed, the deduction due and what is already deducted.
* **Give an advance** (amount, optional monthly instalment, reason). The cash comes out of the advances wallet.
* Advances created automatically from a driver's personal spending on a trip are marked *From a trip*; they are corrected on the trip, not cancelled here.
* **Payroll:** an advance with an instalment is deducted instalment by instalment; an advance **without** an instalment is deducted in full at the next payroll. *Apply deductions* records them for the chosen month — **each advance once per month** (a database key stops a double press). *Excel sheet* gives the accountant the list.
* **Cash repayment** (never more than what is left). **Cancel** only for a manual advance with nothing repaid.
* Permissions: *create* gives an advance · *edit* changes the instalment and takes cash repayments · *approve* applies the payroll · *delete* cancels.
* Code: `Office\AdvanceController`, `Advances\AdvanceService`, `Pages/Office/Advances/Index.vue`.

## 3. Invoice numbers (`/office/invoices`)

* Tabs **Linked invoices · Closed trips without an invoice**; tiles for invoices, invoiced value, trips without an invoice (count, value) and the longest wait.
* **Link:** choose the customer, type the number (as printed by the ERP), tick the settled trips. **One invoice can cover several trips of one customer.** Typing an existing number adds trips to that invoice.
* Only **settled** trips can be invoiced, a trip has at most one invoice, and an invoice number belongs to one customer.
* Edit an invoice (number, date, add / remove trips) or remove the link (trips return to "without an invoice").
* The invoice number shows on the trip list (and is searchable), on the trip page, and in the **client portal**: shipments list, statement (column, Excel and print) with *Invoiced / Not yet invoiced* totals.
* Code: `Office\InvoiceController`, `Invoices\InvoiceService`, `Pages/Office/Invoices/Index.vue`.

## 4. Month close & true profit (`/office/close`)

**The rule (Scope §10):** `rate = the month's G&A ÷ own-fleet km`, and each trip carries `its km in that month × rate`.

* Month chips show *Open · Closed · Re-opened*.
* **G&A lines**: standard lines (drivers' salaries, office salaries, depreciation, maintenance, tyres, insurance, licences, rent, other) or your own name. Add, change, delete, or **import from Excel** (a template is provided).
* The screen shows the flow **G&A ÷ km = rate**, the month's **revenue, direct profit and true profit**, and every trip of the month with its km, G&A share and true profit — flagging trips **split between two months** and trips **still on the road**.
* **Close** is allowed only when the month has ended and has both G&A lines and km. It locks the G&A lines, saves the rate and writes each trip's share (`trip_allocations`).
* **Re-open** needs the *Re-open* permission and a **reason**; it is audited.
* **Excel report** of the month.
* Code: `Office\MonthCloseController`, `Closing\{MonthCloseService, Allocator, MonthSplit, TrueProfit}`, `Pages/Office/Close/Index.vue`.

**A trip that spans two months.** The trip's span is *departed → delivered*. Settings → *Trip spanning two months* chooses the rule: **by hours** (each month takes its share of the km), **start month** or **delivery month**. The parts always add up to the trip's km. A closed month keeps its saved part; open months share the remaining km.

**On the trip page (profitability panel)** the G&A share is shown per month (with *month closed / open*), and true profit says **Final** or **Estimate**.

* **Final** — the trip is delivered and every month it touches is closed.
* **Estimate** — otherwise; it uses the last closed month's rate, or the *G&A per km estimate* from Settings until the first month is closed.

Hired trucks carry no G&A unless Settings → *G&A basis* is *All km*. Month figures count trips **delivered in the month**; true profit = direct profit − the month's G&A total.

## 5. Other changes

* **Settings**: new *Fuel flag %*.
* **Trips list**: invoice number under the status; search also finds invoice numbers.
* **Driver page**: link to the advances screen. **Home**: road map shows Steps 1–6 ready.

## 6. Files added or changed

| Area | Files |
|---|---|
| Database | `database/migrations/2026_10_06_000001_create_fuel_advances_invoices_and_month_close.php` |
| Models | new `FuelEntry`, `DriverAdvanceRepayment`, `Invoice`, `GaEntry`, `MonthClose`, `TripAllocation`; changed `Trip`, `DriverAdvance`, `CompanySetting` |
| Services | new `Fuel/*`, `Advances/AdvanceService`, `Invoices/InvoiceService`, `Closing/*`; changed `Trips/TripFigures` |
| Controllers | new `Office\{Fuel,Advance,Invoice,MonthClose}Controller`; changed `TripController`, `VehicleController`, `SettingsController`, `Client\StatementController`, `Client\Concerns\ServesClient`, `ComingSoonController` |
| Screens | new `Pages/Office/{Fuel,Advances,Invoices,Close}/Index.vue`; changed `Trips/{Index,Show}`, `Vehicles/Show`, `Drivers/Show`, `Settings/Index`, `Home`, `Client/Statement`, `Client/Shipments/Index`, `Layouts/navigation.js`, `views/client/statement.blade.php` |
| Words | `lang/{en,ar}/finance.php`, `resources/js/lang/{en,ar}.js` (`fuel`, `adv`, `inv`, `close` + small additions) |
| Demo data | `database/seeders/Step6Seeder.php` (called from `DemoSeeder`) |
| Tests | `tests/Feature/{Fuel,Advances,Invoices,MonthClose}Test.php`, `tests/Unit/{MonthSplit,FuelMath}Test.php` |

## 7. Decisions taken (tell me if you want any changed)

1. Only **settled** trips can be invoiced, as in the trip life-cycle.
2. An advance **without** an instalment is taken in full at the next payroll.
3. A refuel tied to a trip is the trip's fuel expense (one amount, counted once).
4. A month closes only after it has ended and has G&A and km.
5. A trip's true profit is **final** only when delivered and all its months are closed.
6. Hired trucks carry no G&A on the default basis.
7. Existing demo users keep their old permissions; the seeder adds the new ones for Mona and Karim.

## 8. Try it by hand

1. `php artisan db:seed` (demo data), sign in as the company admin.
2. **Fuel log** → one truck shows *Possible over-draw*. Add a refuel for a running trip → open that trip: the fuel expense appears once.
3. **Driver advances** → *Payroll deductions* → *Apply deductions* → press it again: nothing is deducted twice.
4. **Invoice numbers** → *Closed trips without an invoice* → link two trips of the same customer to one number → check the trip list and the client's statement.
5. **Month close** → choose last month → G&A lines are already there (left open) → *Close the month*. Open a trip from that month: true profit says **Final**. *Re-open* needs a reason.

Tests: `php artisan test --filter=FuelTest` (and `AdvancesTest`, `InvoicesTest`, `MonthCloseTest`, `MonthSplitTest`, `FuelMathTest`).

> **Verification note.** The Laravel/PHPUnit tests were written alongside the code but could not be run in the environment where this step was built (no Composer access). The PHP was syntax-checked, the pure maths (month split, km/L) was run on its own, and the Vue screens were compiled. Please run `php artisan test` once after installing and report anything red.
