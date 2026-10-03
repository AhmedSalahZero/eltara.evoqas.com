# Step 3 — Trips, expenses, the three wallets, transfers & approvals, settlement

<!-- El Tara — docs/STEP_03_TRIPS_AND_WALLETS.md — the Step 3 features, how each works, where its code and test are, and how to try it by hand. -->

Step 3 is the heart of El Tara: every trip gets its own revenue and expenses, and every pound in a driver's hands sits in one of his wallets, where it is recorded, approved and finally settled.

All screens are in the **Company Office** (`/office`). The Driver App screens for the same actions arrive in Step 4. They will use exactly the same rules, because the rules live in one place (`app/Services/Trips`), not in the screens.

## Installing this step on your computer

The update adds new tables. It does not change or delete anything you already have.

```
php artisan migrate
php artisan db:seed
npm run build
php artisan optimize:clear
php artisan test
```

- `db:seed` adds the Step 3 demo trips to "Nile Heavy Transport". It runs once; if the company already has trips, it adds nothing.
- `php artisan test` runs every automated test, old and new. All of them should pass (green). If one fails, send me the red lines.

---

## 1. Trips list — `/office/trips` (Scope §6.3)

**What it does.**
- Search by trip number, driver, customer or plate. Filter by loading date (from / to).
- Status chips with counts: all · on the way · to settle · planned · settled · cancelled.
- Four tiles add up **everything filtered**, not just the page: trips and km, revenue, direct cost, direct profit with margin.
- Trips on the way come first, then those waiting for settlement.
- For trips on the way, the last column shows the cash the driver holds on that trip (custody in blue, collections in violet).
- **Excel** downloads the filtered list (Arabic sheets are right-to-left).
- **New trip** opens the trip form.

**The new-trip form.**
- Choose the customer and the route. Routes where this customer has an agreed price are listed first, with the price.
- **Price:** it fills in from the rate card. Typing a different price, or a price for a route with no rate card, needs the **Trips → Edit price** permission.
- **Truck:** trucks in maintenance, and trucks that already have a booked next trip, cannot be chosen. The truck's usual driver is suggested.
- **Custody:** it fills in from the route budget = cash road costs + buffer %, rounded up to 500 (e.g. 3,000 for Obour → Alexandria).
- **Hired truck:** no custody. The form asks for the owner's fee, which is recorded as a cost paid by the company.
- **Cargo (goods) and Weight:** two separate fields. *Cargo* is the goods (cement, steel, cheese …) picked from a list; **+ Add new** under the selector adds a new goods type without leaving the form. *Weight (tons)* is the tonnage carried. Both feed future reports (by cargo, by weight). The lists can be renamed or hidden in Company settings.
- **Transfer policy:** starts from Company settings, and can be different for this trip. The first choice is called **Need approval** (يجب أخذ موافقة).
- km, usual hours and the standard budget are **copied** into the trip, so later changes to the route never rewrite an old trip.
- Trip numbers run T-00001, T-00002 … per company.

**Code.** `app/Http/Controllers/Office/TripController.php`, `app/Services/Trips/TripService.php`, `resources/js/Pages/Office/Trips/Index.vue`, `resources/js/Components/TripForm.vue`.
**Test.** `tests/Feature/TripsTest.php`

## 2. The trip file and its lifecycle — `/office/trips/{id}`

**Header.** Route, trip number, status, customer, truck, driver (with mobile), distance, loading and delivery times, and the step tracker:

Planned → Accepted → Custody → Loading → On the road → Delivered → Settled

There is **one "next step" button**, as in the demo:

| Trip is… | Button | What it needs |
|---|---|---|
| Planned | Accept for the driver | a driver (own trucks); the truck and driver not busy on another trip |
| Accepted | Hand over custody | the amount (the planned custody is suggested) |
| Accepted, custody given | Start loading | — |
| Loading | Depart | — |
| On the road | Confirm delivery | **the photo of the stamped delivery note** (required) and the receiver's name |
| Delivered | Settle the trip | see §6 |

- Every step records its time and who did it on the **Key moments** timeline.
- From Step 4 the driver presses these buttons in his app, and the timeline also shows **where** he was. The office can always record a step for him (for example, when he phones in).
- **Printable trip order** — for the driver: route, truck, loading time, cargo, custody and the usual road costs, with signature boxes. It shows **no prices**.
- **Edit** — loading time, cargo, notes, planned custody, "client pays cash". The truck and driver can change only while the trip is planned. The price needs "Edit price", and every price change is audited and shown on the timeline.
- **Cancel** — only before any money has moved on the trip. After that, the trip is settled instead.

**Other panels.**
- **Revenue & expenses:** freight (from the rate card or typed), extra charges and deductions, and every expense line with who paid it, the usual amount for the route and an **Over budget** flag.
- **Budget vs actual:** a bar per category. A category more than the company's **over-budget %** above its standard is red (15% unless the company changed it — see §4).
- **Profitability:** revenue, cost and profit per km, direct profit and margin, the G&A share (km × rate) and the **true profit**.
  - Until month close exists (Step 6), true profit uses the "G&A per km estimate" from Company settings and is marked *estimate*.
  - A hired truck carries no G&A.
- **Photos:** the delivery note, receipts, and cash-from-client receipts. Photos are private: they open only through the office, for people with "Trips → View".

## 3. The three wallets (Scope §6.4, §10)

Every driver has three wallets. Each trip has its own custody and collections.

| Wallet | What it is | Goes up when | Goes down when |
|---|---|---|---|
| **Custody** (عهدة) | company money for road costs | custody is handed over, a transfer comes in | he spends from it, personal spending moves to advances, he returns it at settlement |
| **Collections** (تحصيل) | client money he holds for the company | the client hands him cash (driver side confirmed) | a transfer to custody, he hands it in at settlement |
| **Advances** (سلف) | personal loans, deducted from salary | he spends custody on himself | salary deductions (Step 6) |

There is also **own pocket**: money he paid from his own pocket for the trip. The company owes it back, and it is refunded at settlement.

**Formulas (Scope §10):**
- Custody balance = issued + transfers in − spent − moved to advances − returned
- Collections balance = confirmed received − transferred out − handed in
- Net at settlement = custody + collections − own-pocket refund

**How it is kept honest — the ledger.**
- Every movement of money is one row in `wallet_entries`, with who, when and why. A balance is simply the sum of its rows.
- Rows can **never be changed or deleted** (the program refuses it, and a test checks this).
- Correcting an expense adds a **correction row**. For example, 1,200 corrected to 1,000 leaves the −1,200 row and adds +200. The history always adds up and shows what happened.

**Where you see them.**
- **Trip file:** the three wallet cards with issued / spent / moved / returned lines.
- **Driver file:** the driver's total balances across all his trips, plus his latest trips with budget vs actual.
- **Drivers list:** new columns for trips this month and cash held.
- **Wallets & settlement** screen (§5).

## 4. Expenses (Scope §6.3, §6.4, §8.2)

**Add expense** on the trip file:
- Pick the category (big buttons, as in the Driver App), the amount (the usual amount for this route is shown, with a warning above it), the time, a note and an optional receipt photo.
- **The over-budget flag is a company setting:** Company settings → Wallets & approvals → "Flag an expense when it is more than … % above its usual amount" (15% by default, as in the scope). The trip page, the expense form, the budget bars and the Routes page all follow it. Changing it is recorded in the audit log.
- **Paid from:**

| Paid from | What happens |
|---|---|
| Custody | custody goes down |
| Collection money | a **collections → custody transfer** is recorded with it. The screen says whether the trip's policy lets it go through at once or waits for approval |
| His own pocket | the company owes him; refunded at settlement |
| The company | nothing in the wallets (fuel card, the hired-truck fee …) |

- **Personal** spending is not a trip cost.
  - With the setting "Personal spend → advance" ON (default), it leaves custody and becomes a **driver advance**, marked "from trip".
  - With the setting OFF, it stays in custody, so he hands it back at settlement.
  - It cannot be paid "from his own pocket".
- **Editing** an expense only touches the wallets if the amount or "paid from" changed. Fixing a note does not re-ask for approval.
- **Deleting** an expense corrects the wallets and cancels the transfer and advance it created.
- Every add / edit / delete is written to the audit log.
- Nothing can change once the trip is settled.

**Permissions.** `trip_expenses.create / edit / delete` (and `trips.view` to see them).

## 5. Transfers & approvals — and the Wallets & settlement screen

**Transfers** move collection money into custody. They are never silent: each one has an amount, a reason, a time and a status.

**The trip's transfer policy** (company default, changeable per trip under the wallet cards):

| Policy | Result |
|---|---|
| Approval always required | waits for a manager |
| Automatic up to a limit (e.g. 1,000) | up to the limit: goes through at once; above: waits |
| Automatic, no approval (night / long trips) | always goes through at once |

- Automatic transfers still appear in **"to review"** until someone marks them reviewed (the "next morning" check).
- **Who can approve:**
  - the company admin — any amount;
  - an office user with "Wallet transfers → Approve" — up to their **own approval limit** (set on the Users screen).
  - Above the limit, the button is replaced by "the company admin approves".
- A **rejected** transfer moves nothing, and the reason is kept (the driver will see it in Step 4). A reason is required.
- A transfer can never be larger than the collection money the driver holds on the trip (minus transfers already waiting).

**Wallets & settlement** — `/office/wallets`:
- **Tiles:** custody with drivers, collections with drivers (and how much clients have not confirmed yet), advances outstanding, and own-pocket money owed to drivers.
- **Tabs:**
  - *Waiting for approval* (approve / reject);
  - *Automatic — to review* (mark reviewed);
  - *Trips to settle*: delivered trips with what each driver hands over, marked *Ready* or *Needs attention*;
  - *Driver balances*: every driver, biggest holders first.

**Office start page:** a new blue note links to transfers waiting, automatic ones to review, and trips to settle.

## 6. Cash from the client (Scope §9 — anti-fraud)

- The office can record cash the client handed the driver, as the driver reported it, with an optional receipt photo. It counts on the driver's side at once.
- The **client** confirms it from the client portal in Step 5; until then it shows *Waiting for the client*.
- In Step 4, amounts the client records wait for the **driver's** confirmation.
- A **disputed** amount does not count in the collections wallet until management resolves it, with *It was received* or *It was not received*.
  - Resolving needs "Wallet transfers → Approve".
  - Disputes come from the client portal and Driver App (Steps 4–5); the rule and the resolve button are ready now.

## 7. Settlement (Scope §6.5)

The settlement panel appears on the trip file once the trip is delivered.

- It shows: custody (the driver returns it, or it is refunded to him if he spent more), collections (he hands them in), and the own-pocket refund.
- Then the big figure: **"The driver hands over now"**, or **"The company pays the driver"**.
- **The trip cannot be settled** while:
  - it is not delivered with its proof photo;
  - a transfer is still waiting for approval;
  - a cash amount is in an open dispute.
- **Warnings** (they do not block): amounts the client has not confirmed yet, and amounts the client recorded that the driver has not confirmed (these are not counted).
- **Settle and close** needs **Trip settlement → Approve**. It then:
  - writes **one entry per wallet**, bringing each trip wallet to zero;
  - saves the settlement record (balances, net, unconfirmed amount, note, who, when);
  - marks the trip *Settled*;
  - writes it to the audit log.
- Two people pressing *Settle* at the same moment cannot settle twice.
- After settlement nothing on the trip can change.
- Advances are not settled here — they are deducted from salary (Step 6).

---

## Permissions used in Step 3

| Action | Permission |
|---|---|
| See trips, the trip file, photos, print, Excel | Trips → View |
| New trip | Trips → Create |
| Steps, delivery, edit, extras / deductions, transfer policy | Trips → Edit |
| A price different from the rate card | Trips → Edit price |
| Cancel a trip | Trips → Delete |
| Add / edit / delete expenses | Trip expenses → Create / Edit / Delete |
| Hand over custody, record cash from client, ask for a transfer | Wallet transfers → Create |
| Approve / reject / review transfers, resolve disputes | Wallet transfers → Approve (+ approval limit) |
| Wallets & settlement screen | Wallet transfers → View |
| Settle a trip | Trip settlement → Approve |

## Decisions I took (tell me if you want any changed)

1. **Handing over custody** uses "Wallet transfers → Create". It puts money into a driver's wallet, and the scope's list has no separate custody line.
2. **One trip in progress + one booked next trip** per truck and per driver. This allows "next trip booked" on the dashboard (Step 7) and prevents double-booking.
3. **Customer and route cannot change after a trip is created**, because they fix the price and the budget. Cancel and create a new trip instead (possible until money has moved).
4. **Personal spending with the setting OFF** stays in custody and is handed back at settlement.
5. **"Trip expenses → Approve"** is kept on the permission list but not used yet. The scope does not describe approving expenses themselves. It can later be used, for example, to accept or refuse an over-budget expense.
6. **Notifications** (transfer approved, trip settled …) are sent from Step 7, which builds notifications. Everything they need is already recorded.
7. **Invoice numbers** (the last step of the lifecycle) come with Step 6.
8. **The office records cash from the client "as the driver reported it"**, so it counts on the driver's side. The client still confirms it.

## Also in this step

- **Vehicles:** the list shows **On trip T-000xx** for trucks with a trip in progress. The vehicle file now shows this month's trips, revenue, direct profit per km and its latest trips.
- **Drivers:** the driver file shows the live wallets and latest trips; the list shows trips this month and cash held.
- **Menu:** *Trips* and *Wallets & settlement* now open real screens.
- **A fix to the look:** the grey badge (`.bd.pl`) shared its colour name with the P&L list style (`.pl`), which made grey badges stretch across the whole cell. They now stay badge-sized everywhere.
- **Company settings:** new box for the over-budget flag % (default 15).
- **Old tests updated:** two Step 1 tests opened the "coming soon" trips page; they now open the real Trips screen.

## Demo data (what `db:seed` adds)

"Nile Heavy Transport" gets trips in every state:
- 7 settled trips over the last weeks;
- 3 on the road:
  - one with a **1,800 transfer waiting for approval** — above Mona's 1,000 limit, so only Ahmed can approve it;
  - one night trip with **automatic transfers to review** and a personal expense that became an advance;
  - one over budget on fuel, with tolls paid from the driver's pocket;
- 1 loading and 1 accepted;
- 2 delivered, waiting for settlement — one blocked by a **disputed 500**;
- 2 planned (one on a hired truck with its owner's fee) and 1 cancelled.

They were made through the same rules as real use, at the right times in the past, so every balance adds up. Photos are drawn placeholders marked DEMO.

## Try it by hand

Sign in as `ahmed@nile-transport.test` (company admin).

1. **Trips.** Click the chips (*On the way*, *To settle*), search `T-000`, and press **Excel**.
2. **Waiting for approval.**
   - Open *Wallets & settlement*: one transfer of 1,800 waits. Approve it.
   - Sign in as `mona@…` in an Incognito window. She sees "the company admin approves", because 1,800 is above her 1,000 limit.
3. **A trip on the road.**
   - Open it and add an expense paid from **collection money**. The form tells you whether it passes at once.
   - Add a **personal** expense and watch custody go down and *Advances* go up.
   - Delete the expense: the wallets return to where they were.
4. **Settle.**
   - *Wallets & settlement → Trips to settle*: open the one marked *Ready*.
   - Check the net amount, then press **Settle and close**.
   - Open the other one: it cannot be settled until you **resolve** the disputed 500 in the *Cash from the client* panel.
5. **A whole trip.**
   - **New trip** → pick a customer and route (the price and custody fill in).
   - Then press the next-step button: accept → custody → loading → depart → delivery (any photo) → settle.
6. **Print** the trip order of any trip: no prices on it.
7. **Karim (CFO)** can settle but cannot add trips; **Mona** can add trips but cannot settle.

## Where the code is

| Part | Files |
|---|---|
| Tables | `database/migrations/2026_10_02_000001_create_trips_and_wallets.php` |
| Models | `app/Models/{Trip,TripCharge,TripExpense,TripCollection,WalletTransfer,WalletEntry,DriverAdvance,TripSettlement,TripEvent}.php` |
| The rules | `app/Services/Trips/` — `TripService` (create, lifecycle), `ExpenseService`, `CollectionService`, `TransferService`, `SettlementService`, `WalletLedger` (the only writer of the ledger), `TripFigures` (numbers for screens), `Actor`, `TripRuleException` |
| Office controllers | `app/Http/Controllers/Office/{TripController,TripActionController,TripMoneyController,WalletController}.php` |
| Screens | `resources/js/Pages/Office/Trips/{Index,Show}.vue`, `resources/js/Pages/Office/Wallets/Index.vue`, `resources/js/Components/{TripForm,TripStatus}.vue` |
| Trip order | `resources/views/trips/print.blade.php` |
| Messages | `lang/{ar,en}/trips.php`, `resources/js/lang/{ar,en}.js` (sections `trip`, `wal`) |
| Demo data | `database/seeders/TripSeeder.php` |
| Tests | `tests/Feature/{TripsTest,WalletsTest,SettlementTest}.php`, helper `tests/Concerns/BuildsTrips.php` |

---

## Update — cargo, weight, truck types, labels

- **Amount boxes** accept any amount (2,000 was refused because the boxes only allowed 1, 51, 101 … 1,951, 2,001).
- **Trips:** *Cargo* (goods, from a list with **+ Add new**) and *Weight (tons)* are separate fields; both show on the trip file, the trip order and the Excel export. Old trips keep their old cargo text as a goods type.
- **Trucks:** *Vehicle* is now *Truck / شاحنة* everywhere. **Truck type** is a list of 12 standard types (Small Pickup … Trailer, from Trucks.xlsx) plus any the company adds (**+ Add new** under the selector). Rename or hide them in Company settings. Old trucks were mapped: trailer → Tractor-Trailer, jumbo → Extra Heavy Truck, heavy → Heavy Truck, tanker → Tanker Truck, other → empty (please pick).
- *Next trip booked* now reads **محجوزة لرحلة قادمة** in Arabic.
- New code: `app/Models/{VehicleType,CargoType}.php`, `app/Http/Controllers/Office/LookupListController.php`, `resources/js/Components/ListSelect.vue`, migration `2026_10_03_000001_add_cargo_weight_and_truck_types.php`, test `tests/Feature/CargoAndTruckTypesTest.php`.
