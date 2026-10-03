// ══════════════════════════════════════════════════════════════════
//  El Tara — Number and date formatting
//  Location: resources/js/Utils/format.js
//
//  Scope §2: Western digits (123, never ١٢٣), thousands separators,
//  currency EGP. Used everywhere a number or date is shown.
//    money(12500)      → "12,500"      money(12500.5, 2) → "12,500.50"
//    num(1500)         → "1,500"
//    date('2026-09-30')→ "30/09/2026"   (dates and times are always in the company's zone — Cairo)
//    ago(iso)          → "3 h" / "2 d" (for "last seen")
// ══════════════════════════════════════════════════════════════════

const numberFormat = (digits) => new Intl.NumberFormat('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });

export const num = (value, digits = 0) => (value == null || value === '' ? '—' : numberFormat(digits).format(Number(value)));

export const money = (value, digits = 0) => num(value, digits);

// ── Dates and times: ALWAYS in the company's time zone (Cairo), never the browser's ──
//  A laptop or phone set to another time zone (or a wrong clock setting) must not
//  change the time a trip was loaded, a receipt was spent, or which day "today" is.
//  The server tells the screens its zone (props.timezone); Cairo is the default.

let TZ = 'Africa/Cairo';
let zoneFormat = null;

export function setTimezone(zone) {
    if (!zone || zone === TZ) return;
    try {
        new Intl.DateTimeFormat('en-US', { timeZone: zone });
        TZ = zone;
        zoneFormat = null;
    } catch {
        /* an unknown zone name: keep the current one */
    }
}

export const timezone = () => TZ;

// "2026-09-30", "2026-09-30 14:05:00", "2026-09-30T14:05" — no zone in the text = already company time.
const WALL = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?)?$/;

/** A date or moment as { y, m, d, h, i } in the company's time zone, or null when it is not a date. */
export function zoned(value) {
    if (value == null || value === '') return null;

    if (typeof value === 'string') {
        const m = WALL.exec(value);
        if (m) return { y: +m[1], m: +m[2], d: +m[3], h: +(m[4] ?? 0), i: +(m[5] ?? 0) };
    }

    const when = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(when.getTime())) return null;

    zoneFormat ??= new Intl.DateTimeFormat('en-GB', { timeZone: TZ, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    const part = (type) => Number(zoneFormat.formatToParts(when).find((p) => p.type === type).value);

    return { y: part('year'), m: part('month'), d: part('day'), h: part('hour'), i: part('minute') };
}

const p2 = (n) => String(n).padStart(2, '0');

export function date(value) {
    const z = zoned(value);

    return z ? `${p2(z.d)}/${p2(z.m)}/${z.y}` : '—';
}

export function time(value) {
    const z = zoned(value);

    return z ? `${p2(z.h)}:${p2(z.i)}` : '—';
}

/** A moment → what a datetime-local box wants: "2026-10-02T08:00" (company time). */
export function inputValue(value) {
    const z = zoned(value);

    return z ? `${z.y}-${p2(z.m)}-${p2(z.d)}T${p2(z.h)}:${p2(z.i)}` : '';
}

/** Now (plus an optional number of milliseconds) as a datetime-local value, in company time. */
export const nowInput = (addMs = 0) => inputValue(new Date(Date.now() + addMs));

/** "2026-09-30" for year / month (1-12) / day; months and days may overflow (day 0 = last day of the month before). */
export const isoDate = (y, m, d) => new Date(Date.UTC(y, m - 1, d)).toISOString().slice(0, 10);

/** Today's date in company time, as { y, m, d }. */
export const today = () => zoned(new Date());

/** Today (plus a number of days) as "YYYY-MM-DD" in company time. */
export function todayIso(addDays = 0) {
    const t = today();

    return isoDate(t.y, t.m, t.d + addDays);
}

/** Short "how long ago", in the given language. */
export function ago(value, locale = 'ar') {
    if (!value) return null;
    const minutes = Math.max(0, Math.round((Date.now() - new Date(value).getTime()) / 60000));
    const ar = locale === 'ar';
    if (minutes < 2) return ar ? 'الآن' : 'Just now';
    if (minutes < 60) return ar ? `منذ ${minutes} دقيقة` : `${minutes} min ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return ar ? `منذ ${hours} ساعة` : `${hours} h ago`;
    const days = Math.round(hours / 24);
    return ar ? `منذ ${days} يوم` : `${days} d ago`;
}

export const percent = (used, limit) => (limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0);

/** A stable avatar colour from a name (as in the demo). */
export function avatarColor(name = '') {
    const colors = ['#3E82D6', '#1490A8', '#26C08C', '#E08A3C', '#C264A8', '#BA7517'];
    let h = 0;
    for (const ch of name) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
    return colors[h % colors.length];
}

/** Short money for tiles and chart axes: 12,400 → "12.4K", 1,250,000 → "1.25M" (as the demo's MK). */
export function compact(value) {
    if (value == null || value === '') return '—';
    const n = Number(value);
    const a = Math.abs(n);
    const s = n < 0 ? '-' : '';
    if (a >= 1e6) return s + num(a / 1e6, 2) + 'M';
    if (a >= 1e4) return s + num(a / 1e3, 0) + 'K';
    if (a >= 1e3) return s + num(a / 1e3, 1) + 'K';
    return s + num(a);
}
