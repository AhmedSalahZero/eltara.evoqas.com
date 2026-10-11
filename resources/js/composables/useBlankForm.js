// ══════════════════════════════════════════════════════════════════
//  El Tara — A pop-up form that always opens EMPTY
//  Location: resources/js/composables/useBlankForm.js
//
//  const give = useBlankForm({ amount: '', reason: '' });
//  function openGive() { give.startFresh(); modal.value = true; }
//
//  Why: after a form is saved successfully, Inertia remembers the
//  SAVED values as the new "starting point". A plain form.reset()
//  then brings the saved values back, so the next time the pop-up
//  opened it was pre-filled with the previous entry (an old amount,
//  a ticked "replace" box, a hidden file…).
//  startFresh() puts the real blank values back first, clears old
//  error messages, and can set values for this opening only:
//      give.startFresh({ amount: advance.remaining })
// ══════════════════════════════════════════════════════════════════

import { useForm } from '@inertiajs/vue3';

export function useBlankForm(initial) {
    const blank = JSON.parse(JSON.stringify(initial));
    const form = useForm(initial);

    form.startFresh = (extra = {}) => {
        form.defaults({ ...JSON.parse(JSON.stringify(blank)), ...extra });
        form.reset();
        form.clearErrors();
        return form;
    };

    return form;
}
