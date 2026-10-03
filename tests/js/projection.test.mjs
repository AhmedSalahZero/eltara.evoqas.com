// El Tara — Test: what the Driver App screens show (offline projection). Run: node tests/js/projection.test.mjs
// Location: tests/js/projection.test.mjs
import { project, currentTrip, cashToConfirm } from '../../resources/js/driver/projection.js';
import assert from 'node:assert';
const snap = { wallets: { custody: 3000, collections: 0, advances: 0, pocket: 0 }, trips: [
  { id: 1, status: 'accepted', transfer_policy: 'limit', auto_transfer_limit: 1000, wallets: { custody: 3000, collections: 0 }, expenses: [], collections: [{ id: 9, amount: 700, state: 'awaiting_driver' }], transfers: [] },
  { id: 2, status: 'planned', wallets: {}, expenses: [], collections: [], transfers: [] } ] };
let id = 0; const e = (type, payload) => ({ id: ++id, uuid: 'u' + id, type, payload, status: 'pending', recordedAt: '2026-10-01T10:00:00Z' });
const v = project(snap, [
  e('trip.loading', { trip_id: 1 }), e('trip.depart', { trip_id: 1 }),
  e('trip.expense', { trip_id: 1, paid_from: 'custody', amount: 500.5, expense_category_id: 3 }),
  e('trip.collection', { trip_id: 1, amount: 5000 }),
  e('trip.transfer', { trip_id: 1, amount: 800, reason: 'x' }),
  e('trip.transfer', { trip_id: 1, amount: 2000, reason: 'y' }),
  e('collection.confirm', { collection_id: 9 }),
  e('trip.expense', { trip_id: 1, paid_from: 'own_pocket', amount: 100 }),
]);
const t = v.trips[0];
assert.equal(t.status, 'on_road'); assert.equal(t.next, 'trip.delivery');
assert.equal(t.wallets.custody, 3000 - 500.5 + 800);
assert.equal(t.wallets.collections, 5000 - 800 + 700);
assert.equal(t.wallets.pocket, 100);
assert.equal(v.wallets.custody, t.wallets.custody);
assert.equal(t.transfers[0].status, 'pending'); assert.equal(t.transfers[1].status, 'auto');
assert.equal(t.waiting, 8);
assert.equal(v.trips[1].status, 'planned');
// wrong order is ignored
assert.equal(project(snap, [e('trip.depart', { trip_id: 2 })]).trips[1].status, 'planned');
assert.equal(currentTrip(v.trips).id, 1); assert.equal(cashToConfirm(project(snap, []).trips).length, 1);
assert.equal(project(null).ready, false);
console.log('projection ok');

// Custody signature: a top-up after signing needs a new signature (only for the top-up amount).
{
  const s = { wallets: { custody: 3000 }, trips: [{ id: 1, status: 'accepted', wallets: { custody: 3000 }, expenses: [], collections: [], transfers: [],
    custody: { issued: 3000, signed: 0, received: false, requested: 0, budget: [] } }] };
  assert.equal(project(s, []).trips[0].custody.to_sign, 3000);
  const signed = project(s, [{ id: 1, uuid: 'a', type: 'trip.custody_receive', payload: { trip_id: 1 }, status: 'pending', recordedAt: new Date().toISOString() }]).trips[0].custody;
  assert.equal(signed.received, true);
  assert.equal(signed.to_sign, 0);
  const topUp = project({ ...s, trips: [{ ...s.trips[0], custody: { issued: 4500, signed: 3000, received: false, requested: 0, budget: [] } }] }, []).trips[0].custody;
  assert.equal(topUp.to_sign, 1500);
  console.log('custody signature ok');
}
