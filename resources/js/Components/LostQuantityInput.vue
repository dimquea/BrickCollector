<script setup>
import { ref, watch } from 'vue';
import { patchField } from '@/support/save';
import { t } from '@/i18n';

/**
 * How many of one lot went missing.
 *
 * Saves on change over AJAX, keeping the page as it is: expanded accordions
 * survive, which matters because this field lives several levels deep in one.
 * The field is disabled while the request is in flight, and the previous value
 * comes back if the server refuses it.
 */
const props = defineProps({
    lot: { type: Object, required: true },
    small: { type: Boolean, default: false },
});

const emit = defineEmits(['saved']);

const value = ref(props.lot.lost_qty);
const saving = ref(false);

// The parent may replace the lot after a navigation.
watch(() => props.lot.lost_qty, (next) => (value.value = next));

async function save(event) {
    const next = Number(event.target.value);
    const previous = props.lot.lost_qty;

    if (Number.isNaN(next) || next === previous) {
        return;
    }

    saving.value = true;

    const result = await patchField(
        `/lots/${props.lot.id}`,
        { lost_qty: next },
        { onRevert: () => (value.value = previous) },
    );

    saving.value = false;

    if (result) {
        // The server clamps to what there was, so echo back what it stored
        // rather than what was typed.
        value.value = result.lost_qty;
        props.lot.lost_qty = result.lost_qty;
        emit('saved', result);
    }
}
</script>

<template>
    <input
        type="number"
        class="form-control text-end"
        :class="{ 'form-control-sm': small }"
        min="0"
        :max="lot.qty"
        :value="value"
        :disabled="saving"
        :aria-label="t('lot.lost')"
        @change="save"
    />
</template>
