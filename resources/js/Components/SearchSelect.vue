<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import TomSelect from 'tom-select';

/**
 * Select with a search box, for lists longer than about ten options.
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
</script>

<template>
    <select :id="id" ref="el">
        <option value=""></option>
        <option v-for="option in options" :key="option.value" :value="option.value">
            {{ option.label }}
        </option>
    </select>
</template>
