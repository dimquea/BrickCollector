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
    options: { type: Array, required: true },   // [{ value, label, rgb? }]
    placeholder: { type: String, default: '' },
    id: { type: String, default: undefined },
});

const emit = defineEmits(['update:modelValue']);

const el = ref(null);
let instance = null;

const toOption = (option) => ({
    value: String(option.value),
    text: option.label,
    rgb: option.rgb ?? null,
});

/**
 * Точка цвета — та же, что в таблицах, но собранная строкой: свои строки Tom
 * Select рисует сам и принимает только готовую разметку, а не компонент.
 *
 * Цвет приходит из справочника, но попадает прямо в атрибут style, поэтому
 * пропускается через «только шестнадцатеричные»: проверить дешевле, чем
 * доверять. Не цвет — не точка, и опции без него (теги, места, годы)
 * рисуются как раньше.
 */
function swatch(rgb) {
    const hex = String(rgb ?? '').replace(/[^0-9a-fA-F]/g, '');

    if (hex.length !== 6) {
        return '';
    }

    return '<span class="rounded-circle border d-inline-block align-middle me-1 flex-shrink-0"'
        + ` style="width: 0.75rem; height: 0.75rem; background: #${hex}"></span>`;
}

const row = (data, escape) => `<div>${swatch(data.rgb)}${escape(data.text)}</div>`;

onMounted(() => {
    instance = new TomSelect(el.value, {
        options: props.options.map(toOption),
        items: props.modelValue == null ? [] : [String(props.modelValue)],
        placeholder: props.placeholder,
        allowEmptyOption: true,
        maxOptions: 500,
        // И в списке, и в самом поле: выбранный цвет должен читаться так же,
        // как он читался при выборе.
        render: { option: row, item: row },
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
        instance.addOptions(options.map(toOption));
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
