<!--
  ══════════════════════════════════════════════════════════════════
  El Tara — Modal (pop-up window)
  Location: resources/js/Components/Modal.vue

  <Modal :show="open" :title="t('users.add')" wide @close="open = false">
      …form…
      <template #footer> …buttons… </template>
  </Modal>

  Closes with Esc or a click on the dark background. The page behind
  cannot scroll while it is open.

  Keyboard / screen reader: when it opens, the keyboard focus moves
  inside it; Tab and Shift+Tab stay inside (they never wander to the
  page behind); when it closes, the focus goes back to the button
  that opened it. The window is named by its title, and the close
  button speaks the person's language.
  ══════════════════════════════════════════════════════════════════
-->
<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import AppIcon from './AppIcon.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, default: '' },
    wide: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const dialog = ref(null);
const titleId = `modal-title-${Math.random().toString(36).slice(2, 9)}`;
let opener = null; // the element that had the focus before the window opened

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

const focusables = () => (dialog.value ? [...dialog.value.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null) : []);

function onKey(e) {
    if (e.key === 'Escape') {
        emit('close');

        return;
    }

    if (e.key !== 'Tab') {
        return;
    }

    const items = focusables();

    if (items.length === 0) {
        e.preventDefault();
        dialog.value?.focus();

        return;
    }

    const first = items[0];
    const last = items[items.length - 1];
    const inside = dialog.value?.contains(document.activeElement);

    if (e.shiftKey && (document.activeElement === first || ! inside)) {
        e.preventDefault();
        last.focus();
    } else if (! e.shiftKey && (document.activeElement === last || ! inside)) {
        e.preventDefault();
        first.focus();
    }
}

function release() {
    window.removeEventListener('keydown', onKey);
    document.body.style.overflow = '';

    if (opener && typeof opener.focus === 'function' && document.contains(opener)) {
        opener.focus();
    }
    opener = null;
}

watch(() => props.show, async (open) => {
    if (open) {
        opener = document.activeElement;
        document.body.style.overflow = 'hidden';
        window.addEventListener('keydown', onKey);

        await nextTick();

        // Start on the first field of the form (not on the close button) when there is one.
        const items = focusables();
        const firstField = items.find((el) => ['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName));
        (firstField || items[0] || dialog.value)?.focus();
    } else {
        release();
    }
});

onBeforeUnmount(release);
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="modal-bg" @click.self="emit('close')">
            <div ref="dialog" class="modal" :class="{ w: wide }" role="dialog" aria-modal="true" :aria-labelledby="titleId" tabindex="-1">
                <div class="modal-h">
                    <h3 :id="titleId">{{ title }}</h3>
                    <button type="button" class="ib" :aria-label="t('common.close')" @click="emit('close')"><AppIcon name="x" /></button>
                </div>
                <div class="modal-b"><slot /></div>
                <div v-if="$slots.footer" class="modal-f"><slot name="footer" /></div>
            </div>
        </div>
    </Teleport>
</template>
