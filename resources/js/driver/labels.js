// ══════════════════════════════════════════════════════════════════
//  El Tara — Driver App: names shown on the phone
//  Location: resources/js/driver/labels.js
//
//  The snapshot carries both languages of every name, so the screens
//  pick one here and switching language needs no signal.
//    routeLabel(trip, isAr) → "6 October ← Alexandria – 5 طن"
//                              "6 October → Alexandria – 5 Ton"
// ══════════════════════════════════════════════════════════════════

export const pick = (obj, isAr) => (obj ? (isAr ? obj.name_ar : obj.name_en || obj.name_ar) : '');

export function routeLabel(trip, isAr) {
    const r = trip.route;
    if (!r) return '';
    const from = isAr ? r.origin_ar : r.origin_en;
    const to = isAr ? r.destination_ar : r.destination_en;
    const weight = r.weight_tons == null ? '' : ` – ${+Number(r.weight_tons).toFixed(2)} ${isAr ? 'طن' : 'Ton'}`;

    return `${from}${isAr ? ' ← ' : ' → '}${to}${weight}`;
}

export const STEPS = ['planned', 'accepted', 'loading', 'on_road', 'delivered'];

export const stepIndex = (status) => Math.max(0, STEPS.indexOf(status));
