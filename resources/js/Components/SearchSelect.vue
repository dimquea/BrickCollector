<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import TomSelect from 'tom-select';

/**
 * Every select in a list filter, whatever its length.
 *
 * Tom Select rather than the platform control: one look across the filters,
 * where a native select among Tom Select ones reads as a different kind of
 * control. It started as the choice for lists longer than about ten options;
 * short ones now use it too, for the same reason.
 *
 * Wraps Tom Select so no page ever touches that library directly.
 */
const props = defineProps({
    modelValue: { type: [String, Number, null], default: null },
    options: { type: Array, required: true },   // [{ value, label }]
    placeholder: { type: String, default: '' },
    id: { type: String, default: undefined },
});

const emit = defineEmits(['update:modelValue']);

const el = ref(null);
let instance = null;

onMounted(() => {
    instance = new TomSelect(el.value, {
        options: props.options.map((o) => ({ value: String(o.value), text: o.label })),
        items: props.modelValue == null ? [] : [String(props.modelValue)],
        placeholder: props.placeholder,
        allowEmptyOption: true,
        maxOptions: 500,
        onChange: (value) => emit('update:modelValue', value === '' ? null : value),
    });
});

// Without this, an Inertia navigation leaves the old instance attached to a
// detached DOM node and its listeners alive.
onBeforeUnmount(() => instance?.destroy());

watch(
    () => props.modelValue,
    (value) => {
        const next = value == null ? '' : String(value);

        if (instance && instance.getValue() !== next) {
            instance.setValue(next, true);
        }
    },
);

// Tom Select copies the options once, at creation. Where a list arrives after
// the control is drawn — a dialog that fetches what it offers — the copy stays
// empty and the filter looks broken. Re-filling keeps the current choice: the
// value may well be in the new list too.
watch(
    () => props.options,
    (options) => {
        if (! instance) {
            return;
        }

        const chosen = instance.getValue();

        instance.clearOptions();
        instance.addOption({ value: '', text: '' });
        instance.addOptions(options.map((option) => ({ value: String(option.value), text: option.label })));
        instance.refreshOptions(false);
        instance.setValue(chosen, true);
    },
    { deep: true },
);
</script>

<template>
    <select :id="id" ref="el">
        <option value=""></option>
        <option v-for="option in options" :key="option.value" :value="option.value">
            {{ option.label }}
        </option>
    </select>
</template>
