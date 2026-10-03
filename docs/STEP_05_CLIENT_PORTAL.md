# Step 5 — Client portal, client requests, two-sided cash confirmation

<!-- El Tara — docs/STEP_05_CLIENT_PORTAL.md — how the Client Portal, the office "Client requests" screen, the client side of cash confirmation, ratings, complaints and the bell work; where each piece of code and test lives; how to try it by hand. -->

The client (shipper) now has his own portal at `/client`: he asks for trucks, follows every shipment, confirms or objects to the cash he handed to drivers, sees his statement and agreed prices, rates trips and sends complaints. The office answers requests from **Client requests** in the menu. A **bell** in the top bar of both portals tells each side what happened (Scope §6.2, §7, §9, §11).

A client sees **only his own customer's data** — never costs, profit, custody, driver advances or another client of the same company.

## Installing this step

Adds three tables (`client_requests`, `trip_ratings`, `client_complaints`) and one small data update (follow-up migration `2026_10_05_000002`: requests that already have trips become *assigned*). Nothing is deleted.

```
php artisan migrate
npm run build
php artisan optimize:clear
php artisan test
```

The road map on the office Home page now shows **Steps 1–5 as ready** (Step 4 had been left at "next" by mistake — corrected).

---

## 1. Client requests (client → office)

| What | How it works | Code |
|---|---|---|
| **Request a trip** | The client picks the **place** (from his own agreed prices) and loading date & time (must be in the future), then adds **lines**: *Number of trucks + Weight* for each weight (e.g. 4 × 5 Ton and 2 × 1 Ton in one request; up to 50 trucks in all). Cargo and notes are optional. The form shows each line's subtotal and the expected total. Each line's price is copied onto the request, so it never changes afterwards. The same weight twice is merged into one line. | `Client\RequestController`, `Client/Requests/Create.vue`, `ClientRequestService::submit` |
| **My requests** | Status: *In review · Approved (trucks soon) · Trucks assigned · Declined (with the reason) · Withdrawn*. Once trucks are assigned: plate, driver and status of every truck. A request *in review* or *approved* can be withdrawn. | `Client/Requests/Index.vue` |
| **Office: Client requests** | Tabs **New / Waiting for trucks / Answered / Complaints & ratings**. On a new request the office can **Approve** (yes now, trucks later), or **Assign trucks** straight away, or **Decline** with a reason. Approved requests wait in *Waiting for trucks*, sorted by loading date; one loading within 3 days with no trucks is flagged *Loading soon — no trucks yet* (also a line on the office Home page). | `Office\ClientRequestController`, `Office/ClientRequests/Index.vue` |
| **Assigning** | Now or any time later (before loading). One **planned trip per truck**, made through the same `TripService::create` the Trips screen uses — so every trip rule applies (truck/driver already booked, suspended driver…). **All or nothing**: if one truck is refused, no trip is created and the request stays *new*. Trips keep `client_request_id`; the trip page shows "From client request R-00007". | `ClientRequestService::assign` |
| **Numbers** | `R-00001`, `R-00002`… per company, like trips. | `ClientRequest::numberFor` |

Permissions (Scope §5): *Client requests → View* to see, *Approve* to assign/decline, *Edit* to reply to complaints.

## 2. Shipments (what the client follows)

* **My shipments** — all his trips; filters All / Active / Delivered; truck, driver, status, price, rating. *(The invoice-number column arrives with Step 6.)*
* **Shipment details** — five client-friendly steps (**Confirmed → Truck ready → Loading → On the road → Delivered**) with the time of each; truck and driver with a **Call the driver** button; **expected arrival**; timeline with a map link where a location was recorded; the cash on this trip; price; **proof-of-delivery photo** (viewed/downloaded only through a checked door, never a public link); 1–5 star rating; complaint form.
* **Expected arrival** = departure time + **half of the route's usual round-trip hours**. This is an estimate and is labelled "expected".

Code: `Client\ShipmentController`, `Client/Shipments/*.vue`. Shared rules for "only my customer": `Client\Concerns\ServesClient` (a trip or cash record of another customer → 404).

## 3. Two-sided cash confirmation (Scope §9)

| Who records | Driver side | Client side |
|---|---|---|
| Driver (app) / office | already confirmed | the client **confirms** or **objects** in *Cash handed to drivers* (or on the shipment page) |
| **Client** (*I handed cash to the driver*) | driver confirms in his app (Step 4) | already confirmed |

* An **objection needs a reason**. The amount **stops counting** in the driver's collections until management resolves it (existing Step 3 screen) — same rule as when the driver objects.
* The client can **record cash he handed over** only if the customer is set to *may pay driver cash* (Customers screen) and only on a trip that has started and has a driver.
* *Cash handed to drivers* shows four totals — **Handed · Confirmed by both · Waiting · Disputed** — and the full log with filters.
* All money logic stays in `CollectionService` (one set of rules for office, phone and client). `Actor::client()` records who acted.

## 4. Statement, prices, ratings, complaints, colleagues

| Screen | Content | Code |
|---|---|---|
| **Statement** | Delivered-trips total, planned/running total, cash handed, every trip with its price; date filter; **Excel** export and a **printable page (Save as PDF)**. Invoice numbers and *invoiced / not yet invoiced* appear with **Step 6**. | `Client\StatementController`, `resources/views/client/statement.blade.php` |
| **My agreed prices** | His rate card per route, with *Request on this route* shortcut. | `Client\PriceController` |
| **Ratings & complaints** | His complaints with the office's replies, delivered trips still waiting for a rating, his ratings. A complaint may be about a trip or general. | `Client\FeedbackController`, `ClientComplaint`, `TripRating` |
| **Rating** | 1–5 stars + comment, **once per delivered trip**. Shown on the office trip page and in the office *Complaints & ratings* tab with the average. | `ShipmentController::rate` |
| **Office replies** | Office tab *Complaints & ratings* → *Reply*; the client is notified. | `Office\ClientRequestController::reply` |
| **My company users** | Everyone sees the list. Only the **account admin** adds colleagues (activation e-mail, same as Step 2) and suspends/re-activates them. The admin and yourself cannot be suspended. | `Client\TeamController` |
| **Home** | Banner for cash waiting for confirmation; KPIs (active shipments, trips this month, transport cost this month at agreed prices, average rating); live shipments board; latest requests; recently delivered with a rating shortcut. | `Client\HomeController` |

## 5. The bell (Scope §11, in-app part)

| Who hears | About |
|---|---|
| Office users with *Client requests → View* | new client request · new complaint |
| Office users with *Trips → View* | cash amount **disputed** (by either side) |
| The client's users | request approved / declined · trip delivered · cash to confirm · driver objected to cash · complaint answered (only the one who complained) |

Each line stores a *key and its numbers*, so it is shown in the reader's own language. Click = mark read and go to the screen; *Mark all as read* clears the red dot. A failure to notify **never** blocks the action itself (it is only logged).
Code: `Notifier`, `AppNotification`, `NotificationController`, `Components/NotificationBell.vue`; the list is a shared page prop in `HandleInertiaRequests`.
Not in this step: e-mail/SMS/push for these events and a full notifications history page (Step 7 lists notifications with the reports).

## 6. Office side additions

* **Menu** — *Client requests* is now a real screen (was "coming soon").
* **Home** — a line "N new client requests · N complaints to answer"; road map shows Steps 1–5 ready.
* **Trip page** — "The client's side" card: which request it came from, the rating, complaints about it.

## 7. Files added or changed

| Area | Files |
|---|---|
| Database | `database/migrations/2026_10_05_000001_create_client_requests_and_feedback.php` |
| Models | `ClientRequest`, `TripRating`, `ClientComplaint` (new); `Trip` (+`clientRequest`, `rating`) |
| Services | `ClientRequestService`, `Notifier` (new); `Actor::client`; hooks in `TripService::deliver`, `CollectionService` (record / dispute) |
| Client controllers | `Client\{Home,Request,Shipment,Cash,Statement,Price,Feedback,Team}Controller`, `Client\Concerns\ServesClient` |
| Office | `Office\ClientRequestController`; `HomeController`, `TripController` (small additions) |
| Shared | `NotificationController`, `AppNotification`, `HandleInertiaRequests` (bell prop), `routes/web.php` |
| Screens | `Pages/Client/*` (10), `Pages/Office/ClientRequests/Index.vue`, `Components/{NotificationBell,Stars}.vue`, `PortalLayout.vue`, `navigation.js`, `Office/Home.vue`, `Office/Trips/Show.vue` |
| Words | `lang/{en,ar}/client.php`, `resources/js/lang/{en,ar}.js` (`cp`, `req`, `notif`) |
| Print | `resources/views/client/statement.blade.php` |
| Tests | `tests/Feature/ClientPortalTest.php` |

## 8. Decisions taken (tell me if you want any changed)

1. **Any active user of the client's company** may confirm/object to cash and record cash; only the **account admin** manages users (Scope §7: others "can request and track").
2. **Only routes with an agreed price** can be requested — no price means no automatic trip price.
3. **Approve and assign are separate.** Approve = yes (client told "trucks soon"); Assign = choose a truck per truck asked, trips are created then. Both can be done at once. A request can still be declined or withdrawn while only approved, not after trucks are assigned.
4. **Expected arrival** = half the route's usual round-trip hours after departure.
5. **Invoice numbers / invoiced totals** come with Step 6; the statement says so on screen.
6. **Notifications are in-app only** in this step.

## 9. Try it by hand

1. Office → *Customers* → open a customer with prices → add a portal user (you receive/activate via the e-mail link, or use the existing test account).
2. Sign in as that client → **Request a trip** (2 trucks) → see it *In review*. Office bell shows it.
3. Office → **Client requests** → *Approve* → it moves to *Waiting for trucks* (client bell: approved, trucks soon). Later press *Assign trucks* → pick 2 trucks → trips appear in *Trips* (planned). Client bell: trucks assigned.
4. Run the trip in the Driver App; take cash from the client there → client **Cash** page shows it *Waiting for you* → *Confirm* (or *Object* with a reason → the office bell rings).
5. Deliver the trip → client bell "delivered" → open the shipment → see the proof photo → rate 4 stars, send a complaint.
6. Office → *Client requests → Complaints & ratings* → reply → client bell.

Tests: `php artisan test --filter=ClientPortalTest`


## Request lines (follow-up)

- New table `client_request_lines` (request, route-with-weight, trucks, agreed price) — run `php artisan migrate`; existing requests are converted to one line each.
- `client_requests.trucks_count` stays as the total; `expected_unit_price` is no longer used (price lives on the lines).
- Assigning needs exactly one truck per truck asked, in line order; trips are created on the matching route and price.
