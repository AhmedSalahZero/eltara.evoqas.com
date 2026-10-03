// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver App: what the screens show (server copy + waiting entries)
//  Location: resources/js/driver/projection.js
//
//  The phone keeps two things: the last copy of the trips from the
//  server (the "snapshot") and the entries the driver recorded that
//  have not uploaded yet (the outbox). What the driver sees is the
//  snapshot with those waiting entries applied on top, so what he
//  just did shows at once — with no signal — and his balances move
//  straight away. When the entries upload, the server copy already
//  holds them and the waiting ones disappear.
//
//  This mirrors the server rules (App\Services\Trips) only as far as
//  display goes; the server remains the judge. An entry the server
//  refuses later is shown in Alerts and its effect vanishes here.
//
//  Pure function (no Vue, no network) so it can be tested alone.
// ══════════════════════════════════════════════════════════════════

const ZERO = { custody: 0, collections: 0, advances: 0, pocket: 0 };

const round = (n) => Math.round(n * 100) / 100;

export const NEXT = { planned: 'trip.accept', accepted: 'trip.loading', loading: 'trip.depart', on_road: 'trip.delivery' };
const AFTER = { 'trip.accept': ['planned', 'accepted'], 'trip.loading': ['accepted', 'loading'], 'trip.depart': ['loading', 'on_road'], 'trip.delivery': ['on_road', 'delivered'] };

export function project(snapshot, entries = []) {
    if (!snapshot) return { trips: [], wallets: { ...ZERO }, ready: false };

    const trips = JSON.parse(JSON.stringify(snapshot.trips ?? []));
    const wallets = { ...ZERO, ...(snapshot.wallets ?? {}) };

    for (const t of trips) {
        t.wallets = { ...ZERO, ...(t.wallets ?? {}) };
        t.waiting = 0;
        t.custody = { issued: 0, signed: 0, received: false, requested: 0, budget: [], ...(t.custody ?? {}) };
    }

    const move = (t, wallet, amount) => {
        t.wallets[wallet] = round((t.wallets[wallet] ?? 0) + amount);
        wallets[wallet] = round((wallets[wallet] ?? 0) + amount);
    };

    const ordered = [...entries].filter((e) => e.status === 'pending').sort((a, b) => a.id - b.id);

    for (const e of ordered) {
        const p = e.payload ?? {};
        const trip = trips.find((t) => t.id === p.trip_id);
        const collection = e.type.startsWith('collection.') ? trips.flatMap((t) => t.collections.map((c) => ({ t, c }))).find((x) => x.c.id === p.collection_id) : null;
        const t = trip ?? collection?.t;

        if (!t) continue;
        t.waiting++;

        if (AFTER[e.type]) {
            const [from, to] = AFTER[e.type];
            if (t.status === from) {
                t.status = to;
                if (e.type === 'trip.accept') t.accepted_local = true;
                if (e.type === 'trip.delivery') {
                    t.pod_receiver = p.receiver ?? null;
                    t.delivered_at = e.recordedAt;
                }
            }
            continue;
        }

        switch (e.type) {
            case 'trip.expense': {
                const amount = Number(p.amount) || 0;
                t.expenses.unshift({
                    id: `local-${e.uuid}`, uuid: e.uuid, local: true, category_id: p.expense_category_id ?? null, is_personal: !!p.is_personal,
                    paid_from: p.paid_from, amount, note: p.note ?? null, spent_at: e.recordedAt, has_receipt: !!p.photo,
                });
                if (p.paid_from === 'own_pocket') move(t, 'pocket', amount);
                else move(t, p.paid_from === 'collections' ? 'collections' : 'custody', -amount);
                break;
            }
            case 'trip.collection': {
                const amount = Number(p.amount) || 0;
                t.collections.unshift({ id: `local-${e.uuid}`, uuid: e.uuid, local: true, amount, state: 'awaiting_client', recorded_by: 'driver', received_at: e.recordedAt, note: p.note ?? null });
                move(t, 'collections', amount);
                break;
            }
            case 'trip.transfer': {
                const amount = Number(p.amount) || 0;
                const auto = t.transfer_policy === 'auto' || (t.transfer_policy === 'limit' && amount <= (t.auto_transfer_limit ?? 0) + 0.001);
                t.transfers.unshift({ id: `local-${e.uuid}`, uuid: e.uuid, local: true, amount, status: auto ? 'auto' : 'pending', reason: p.reason, requested_at: e.recordedAt });
                if (auto) {
                    move(t, 'collections', -amount);
                    move(t, 'custody', amount);
                }
                break;
            }
            case 'trip.custody_receive':
                // The signature covers everything handed over so far (a later top-up needs a new one).
                t.custody.signed = t.custody.issued;
                t.custody.received = true;
                break;
            case 'trip.custody_request':
                t.custody.requested++;
                break;
            case 'collection.confirm':
                if (collection.c.state === 'awaiting_driver') {
                    collection.c.state = 'confirmed';
                    move(collection.t, 'collections', collection.c.amount);
                }
                break;
            case 'collection.dispute':
                if (!['disputed', 'cancelled'].includes(collection.c.state)) {
                    if (collection.c.state !== 'awaiting_driver') move(collection.t, 'collections', -collection.c.amount);
                    collection.c.state = 'disputed';
                }
                break;
            default:
        }
    }

    for (const t of trips) {
        t.next = NEXT[t.status] ?? null;
        // The amount still waiting for the driver's signature (all of it at first, only the top-up later).
        t.custody.to_sign = t.custody.received ? 0 : round(Math.max(0, t.custody.issued - (t.custody.signed ?? 0)));
    }

    return { trips, wallets, ready: true };
}

/** The trip the Home screen puts in front: moving first, then accepted, then the next one planned. */
export function currentTrip(trips) {
    const rank = { on_road: 0, loading: 1, accepted: 2, planned: 3, delivered: 4 };

    return [...trips].sort((a, b) => (rank[a.status] ?? 9) - (rank[b.status] ?? 9))[0] ?? null;
}

/** Cash the client recorded that the driver still has to confirm. */
export function cashToConfirm(trips) {
    return trips.flatMap((t) => t.collections.filter((c) => c.state === 'awaiting_driver').map((c) => ({ ...c, trip: t })));
}
