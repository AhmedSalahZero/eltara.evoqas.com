// ══════════════════════════════════════════════════════════════════
//  El Tara — Notification wording (shared by the bell and the page)
//  Location: resources/js/Utils/notify.js
//  A stored line keeps a key and numbers; this writes the sentence in
//  the reader's language (lang key  notif.<key with _ for .>).
// ══════════════════════════════════════════════════════════════════

import { money } from '@/Utils/format';

const DOCUMENTS = { licence: 'doc.licence', insurance: 'doc.insurance', inspection: 'doc.inspection', driving: 'doc.drivingLicence' };

export function notificationText(n, t) {
    const params = { ...n.params };
    if (params.amount != null) params.amount = money(params.amount);
    if (params.document != null && DOCUMENTS[params.document]) params.document = t(DOCUMENTS[params.document]);

    return t('notif.' + n.key.replace(/\./g, '_'), params);
}
