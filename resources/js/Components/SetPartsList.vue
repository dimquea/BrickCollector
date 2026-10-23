<script setup>
import { computed, reactive, watch } from 'vue';
import OwnedLotsTable from '@/Components/OwnedLotsTable.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SortControl from '@/Components/SortControl.vue';
import ExportButton from '@/Components/ExportButton.vue';
import { t } from '@/i18n';

/**
 * Детали экземпляра: отбор, порядок и выгрузка недостачи над таблицей.
 *
 * В большом наборе деталей под тысячу, и найти среди них недостающие можно
 * было, только пролистав всё. Отбор и порядок — на месте, без запроса: состав
 * уже на странице, а перезагрузка схлопнула бы раскрытые группы.
 *
 * Порядок помнится между заходами, как в разделах; отбор — нет: «только
 * недостача» относится к этому набору, а не к привычке смотреть.
 */
const props = defineProps({
    lots: { type: Array, required: true },
    /** Пункты выгрузки недостачи; null — выгружать нечего. */
    shortage: { type: Array, default: null },
});

const emit = defineEmits(['saved']);

const MEMORY = 'brickcollector.sort.set-parts';

function remembered() {
    try {
        return JSON.parse(window.localStorage.getItem(MEMORY) ?? 'null');
    } catch {
        return null;
    }
}

const saved = remembered();

const view = reactive({
    lost: false,
    color: null,
    sort: saved?.sort ?? null,
    dir: saved?.dir ?? 'asc',
});

watch(() => [view.sort, view.dir], () => {
    try {
        window.localStorage.setItem(MEMORY, JSON.stringify({ sort: view.sort, dir: view.dir }));
    } catch {
        // Не сохранилось — порядок на странице и так тот, что выбрали.
    }
});

const parts = computed(() => props.lots.filter((lot) => lot.type === 'P'));

// Недостача — то же, что выгружается: засчитанная строка или парная.
const isShort = (lot) => (lot.counts || lot.is_counterpart) && lot.lost_qty > 0;

const hasShortage = computed(() => parts.value.some(isShort));

const colourOptions = computed(() => {
    const seen = new Map();

    for (const lot of parts.value) {
        if (! seen.has(lot.color_id)) {
            seen.set(lot.color_id, { value: lot.color_id, label: lot.color_name ?? String(lot.color_id), rgb: lot.color_rgb });
        }
    }

    return [...seen.values()].sort((a, b) => a.label.localeCompare(b.label));
});

const sortOptions = computed(() => [
    { value: 'id', label: t('sort.id') },
    { value: 'color', label: t('sort.color') },
    { value: 'qty', label: t('sort.qty') },
]);

const byNumber = (a, b) => a.item_id.localeCompare(b.item_id, undefined, { numeric: true });

const compare = {
    id: byNumber,
    color: (a, b) => (a.color_name ?? '').localeCompare(b.color_name ?? '') || byNumber(a, b),
    qty: (a, b) => a.qty - b.qty || byNumber(a, b),
};

const shown = computed(() => {
    const rows = parts.value.filter((lot) => (! view.lost || isShort(lot))
        && (view.color === null || view.color === '' || String(lot.color_id) === String(view.color)));

    const order = compare[view.sort] ?? compare.id;
    const sign = view.dir === 'desc' ? -1 : 1;

    return [...rows].sort((a, b) => sign * order(a, b));
});

// Отметили «только недостачу», а потом вернули всё на место — отбор должен
// сняться сам, иначе таблица опустеет без видимой причины.
watch(hasShortage, (value) => {
    if (! value) {
        view.lost = false;
    }
});
</script>

<template>
    <div>
        <div class="d-flex flex-wrap align-items-end gap-2 p-2 border-bottom">
            <div v-if="hasShortage" class="form-check mb-2 me-2">
                <input id="setPartsLost" v-model="view.lost" class="form-check-input" type="checkbox" />
                <label class="form-check-label" for="setPartsLost">{{ t('collection.lost_only') }}</label>
            </div>

            <div style="min-width: 10rem" class="flex-grow-1 flex-sm-grow-0">
                <label for="setPartsColour" class="form-label">{{ t('lot.color') }}</label>
                <SearchSelect
                    id="setPartsColour"
                    v-model="view.color"
                    :options="colourOptions"
                    :placeholder="t('catalog.any')"
                />
            </div>

            <SortControl
                id="setPartsSort"
                v-model:by="view.sort"
                v-model:dir="view.dir"
                :options="sortOptions"
                style="min-width: 12rem"
            />

            <div v-if="shortage" class="ms-auto">
                <ExportButton :items="shortage" :label="t('collection.export_shortage')" />
            </div>
        </div>

        <OwnedLotsTable :lots="shown" @saved="emit('saved', $event)" />

        <p v-if="! shown.length" class="text-body-secondary p-3 mb-0">{{ t('catalog.nothing_found') }}</p>
    </div>
</template>
