# Step 4 — Driver App: trips, expenses with photos, cash, delivery — fully offline

<!-- El Tara — docs/STEP_04_DRIVER_APP.md — how the Driver App works for trips, money and photos, with no signal; where each piece of code and test lives; how to try it by hand. -->

The driver now does the whole trip from his phone (`/driver`): accept it, load, leave, spend money with a receipt photo, take cash from the client, move client money to his custody, and deliver with a photo of the stamped note. **Every action is saved on the phone first**, shows on the screen at once, and uploads by itself when there is signal. The driver never sees a price, revenue or profit (Scope §8.1).

All money rules are the same ones the office uses (`app/Services/Trips/*`) — the phone does not have its own copy of the rules.

## Installing this step

Adds one new table (`driver_uploads`). Nothing existing is changed or deleted.

```
php artisan migrate
npm run build
php artisan optimize:clear
php artisan test
```

Optional (checks the phone's offline screens logic, needs only Node): `node tests/js/projection.test.mjs`

---

## 1. How "offline" works

| Piece | What it does | Code |
|---|---|---|
| **Trips snapshot** | When there is signal the phone downloads the driver's open trips (with expenses, cash, transfers, wallet balances, expense categories and the company's app settings) and keeps them. Refreshed after every upload, every 2 minutes online, and with the refresh button. | `app/Services/Driver/DriverSnapshot.php`, `GET /driver/api/snapshot` |
| **Outbox** | Each action is stored as an entry with its own unique id and the real time it happened. Entries upload in the order recorded. The server answers *applied*, *duplicate* (already had it) or *rejected* (with the reason). | `resources/js/driver/store.js`, `app/Sync/SyncProcessor.php` |
| **Live view** | The screens show the server copy **plus** the entries still waiting, so a balance moves the moment the driver saves an expense — even with no signal. Waiting items are marked "not uploaded yet". | `resources/js/driver/projection.js` |
| **Photos** | A photo is shrunk on the phone (about 1600 px, ≤0.5 MB), stored on the phone and given an id. It uploads **before** the entry that uses it; the entry carries only the id. Sending the same photo twice is harmless. After the entry is confirmed the photo is removed from the phone. | `photos.js`, `UploadController`, table `driver_uploads` |
| **Times** | An entry keeps the time the driver did it, not the time the signal came back. The phone sends UTC; the server converts to the company's time. A phone clock running ahead is capped at "now". | `TripEntryHandler` |
| **No double entry** | The same entry sent twice (lost answer, retry) is recorded once. | `SyncProcessor` |

The first sign-in needs internet. After that, opening the app, reading trips and recording work all happen without signal.

## 2. The screens (bottom menu)

- **Home** — the current trip (on the road first), its one big next-step button, quick actions (Add expense · Cash from client · Use client money), and the custody / collections balances. Warns if the phone has not heard from the office for longer than the company's "max hours without sync".
- **My trips** — all open trips; tap one for the details.
- **Trip details** — cargo, weight, truck, loading time; the five steps; the next-step button (asks "Are you sure?"); the trip's three wallets; expenses, cash and transfers of the trip; cash the client says he paid, with **I received it / Not received**.
- **Add expense** — category, amount, paid from (custody / client money / own pocket), note, receipt photo. "Personal" is not a trip cost (the office records an advance). The receipt photo is required when the company setting says so (Settings → Driver App).
- **Cash from client** — amount, note, optional photo. Counts in the driver's collections at once; the client confirms or disputes in the portal.
- **Use client money** — moves collections → custody with a reason. The screen says whether the trip's policy sends it through at once or a manager must approve.
- **Confirm delivery** — photo of the stamped delivery note (required) and the receiver's name.
- **My wallets** — custody, collections, own pocket, advances; overall and per trip.
- **Alerts** — cash waiting for the driver's confirmation, transfers waiting or refused, and entries the office refused (with the reason and a "Got it" button). The Alerts tab shows a red number for these.

## 3. The entries the phone can send (`config/sync.php`)

| Entry | Does | Handler |
|---|---|---|
| `trip.accept` | planned → accepted | `AcceptTripHandler` |
| `trip.loading` | accepted → loading (refused if custody was planned and not yet handed over) | `StartLoadingHandler` |
| `trip.depart` | loading → on the road | `DepartHandler` |
| `trip.delivery` | on the road → delivered, with the note photo and receiver | `DeliveryHandler` |
| `trip.expense` | an expense with its receipt; from custody / client money / own pocket | `ExpenseHandler` |
| `trip.collection` | cash received from the client | `RecordCollectionHandler` |
| `trip.transfer` | collections → custody request (policy decides) | `TransferRequestHandler` |
| `collection.confirm` | the driver confirms cash the client recorded | `ConfirmCollectionHandler` |
| `collection.dispute` | the driver says he did not receive it | `DisputeCollectionHandler` |
| `trip.custody_receive` | the driver signs for the custody handed over | `CustodyReceiveHandler` |
| `trip.custody_request` | the driver asks for more custody | `CustodyRequestHandler` |

**If the server itself has a fault** (not a rule saying no), the upload still answers normally: that entry comes back as `failed`, the entries after it as `retry`, and nothing is saved for them. The phone keeps them and tries again; after 5 failed tries an entry is parked in Alerts (audit Q14).

**Fresh photos only.** A receipt, cash or delivery-note photo must have been taken within `photo_max_age_minutes` (default 15, `.env` key `PHOTO_MAX_AGE_MINUTES`) before the driver saved it. The phone refuses an older gallery picture straight away; the server checks again on upload (audit Q15). The finger signature is not checked.

A rule that says no (trip closed, wrong step, more than the collections balance, missing photo…) does **not** crash anything: the entry is refused with the rule's own message, nothing is half-saved, and the driver reads the reason in Alerts.

Only the driver's own trips and cash can be touched: another driver's trip or cash id is refused.

## 4. Where the code is

- Backend: `app/Sync/Handlers/*`, `app/Services/Driver/DriverSnapshot.php`, `app/Http/Controllers/Driver/{ApiController,UploadController}.php`, `routes/driver.php`, `app/Models/DriverUpload.php`.
- Shared rules gained an optional "time it happened" (`$at`) and accept an already-uploaded photo path: `TripService`, `ExpenseService`, `CollectionService`. Office behaviour is unchanged.
- Phone: `resources/js/driver/` — `store.js`, `projection.js`, `photos.js`, `db.js` (version 2 adds the photos table), `screens/*`, `components/{PhotoPicker,TripCard}.vue`.
- Texts: `resources/js/lang/{en,ar}.js` (block `driver`), `lang/{en,ar}/trips.php`.

## 5. Tests

`tests/Feature/DriverAppTest.php` (18 tests, incl. custody signature, top-up request, snapshot contents): photo upload is private, idempotent and image-only; full accept→delivery offline with the driver's own times; expense with photo comes out of custody at its real time; receipt required by setting; missing photo refused; cash then transfers (auto inside the limit, pending above, refused above the balance); client-recorded cash counts only after the driver confirms; dispute keeps it out; another driver's trip/cash refused; an entry sent twice is recorded once; the snapshot holds only the driver's open trips and never a price; signed-out / read-only behaviour.
`tests/js/projection.test.mjs`: the phone's live balances.

## 6. Try it by hand

1. Office: create a trip (Step 3), make sure the truck has a driver. Hand over custody after the driver accepts.
2. On a phone (or a narrow browser window) open `/driver`, sign in once with signal, open **My trips**.
3. Turn on airplane mode. Accept → start loading → depart. Add a fuel expense with a photo: custody goes down at once and the item says "not uploaded yet".
4. Add cash from the client, then "Use client money" inside and above the trip's limit.
5. Confirm delivery with a photo. Turn the signal back on: the banner empties and the office trip page shows everything at the times you did them, with the photos.

## 6b. Follow-up: checked against the Scope of Work (§8.2, §9, §10, §12)

After the first delivery the Step 4 build was compared line by line with the Scope. These were missing and are now built:

| Scope item | Now |
|---|---|
| **Receive custody** — amount, what it is for (budget lines), finger signature, confirm | `ReceiveCustody.vue`, entry `trip.custody_receive`. The signature is kept with the trip (`trips/{id}/custody/`) and the office timeline shows "Custody received (signed)" with the amount. **Every handover is signed**: after the office issues a top-up the driver signs again, and that signature covers only the new amount (audit Q16). |
| Home **"custody ran out"** | `RequestCustody.vue`, entry `trip.custody_request`: a request with amount and note in the trip timeline; the office answers by issuing a top-up. Moves no money. |
| Add expense: **usual amount for this route + above-usual warning** | Uses the route's standard budget and the company's over-budget %; counts what is already spent on the category. |
| Cash from client: **photo of the receipt signed by the client** | Required when the company requires receipt photos (same setting as expenses). |
| Home: urgent banner for **new trip to accept**, quick actions (expense · cash · custody ran out · trip details), **next trips**, **settlement summary after delivery** (custody + collections − own pocket) | Done. |
| **My trips**: current / upcoming / finished | Tabs; finished = last 20 settled trips. |
| **My wallets**: advances with instalments | Open advances with repaid, remaining and monthly instalment. |
| **My account**: truck and documents with expiry | Truck, driving licence, truck licence, insurance, inspection with "in N days / expired". |

Still for later steps, by the build order: the **Notifications** feed (new trips, transfer approved/rejected, settlement done, document expiring — Step 7) and the office alert when a driver app has not synced (Step 7). Client notification on delivery comes with the client portal (Step 5).

## 7. Not in this step

- Live GPS tracking (by design there is none; location is captured only at key moments, and only when the company turns it on).
- Editing or deleting an expense from the phone after saving it (the office can correct it); the phone can be given this later if drivers ask.
- Office screen to see driver photos upload status; photos already show on the trip page as receipts.
- Push notifications (Step 7).
