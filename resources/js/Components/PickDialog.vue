<script setup>
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Modal } from 'bootstrap';
import { url } from '@/support/base';
import { notify } from '@/support/toasts';
import { exportItems } from '@/support/exports';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import ExportButton from '@/Components/ExportButton.vue';
import { t, tChoice } from '@/i18n';

/**
 * «Подобрать из коллекции»: что из нужного предмету уже есть.
 *
 * Наличие приходит сразу по трём местам — везде, в наборах, в свободных, — и
 * переключатель меняет таблицу на месте. Добавить же можно, только взяв из
 * свободных: деталь из другого набора — часть того набора.
 *
 * Фигурки — отдельным списком, с отметкой у каждой: свободная фигурка — вещь
 * сама по себе, и решить, отправить ли её в коробку, должен человек. Без
 * отметки на фигурку не рассчитывают — ни при добавлении, ни в выгрузке.
 *
 * Добавление уходит после того, как окно закроется: переход при открытом окне
 * оставил бы его подложку висеть над следующей страницей.
 */
const props = defineProps({
    item: { type: Object, required: true },
});

const el = ref(null);
let modal = null;
let pending = null;

const loading = ref(false);
const loaded = ref(false);
const parts = ref([]);
const figures = ref([]);
const lots = ref(0);
const scope = ref('all');
const chosen = ref([]);

const SCOPES = [
    { value: 'all', label: 'pick.scope_all' },
    { value: 'sets', label: 'pick.scope_sets' },
    { value: 'loose', label: 'pick.scope_loose' },
];

const base = computed(() => `/catalog/${props.item.type}/${encodeURIComponent(props.item.id)}/pick`);

async function load() {
    loading.value = true;

    try {
        const { data } = await axios.get(url(base.value));

        parts.value = data.parts;
        figures.value = data.figures;
        lots.value = data.lots;
        // Отмечено то, что есть в свободных: снять отметку проще, чем
        // догадаться, что её надо поставить.
        chosen.value = data.figures.filter((figure) => figure.available.loose > 0).map((figure) => figure.id);
        loaded.value = true;
    } catch (error) {
        notify(error.response?.data?.message ?? t('errors.save_failed'));
    } finally {
        loading.value = false;
    }
}

function open() {
    modal.show();

    // Наличие меняется не за то время, пока окно закрыто, но меняется: окно
    // открывают заново после того, как что-то докупили и добавили.
    load();
}

defineExpose({ open });

const have = (row) => Math.min(row.qty, row.available[scope.value]);

/*
 * Сколько фигурок есть, если на свободную рассчитывают только с отметкой.
 *
 * Отметка говорит «возьми свободную», и только о свободных: фигурка в другом
 * наборе в режиме «Везде» или «В наборах» есть независимо от неё, а
 * неотмеченная свободная — как будто её нет.
 */
const haveFigure = (row) => {
    const loose = chosen.value.includes(row.id) ? row.available.loose : 0;
    const held = { all: row.available.sets + loose, sets: row.available.sets, loose }[scope.value];

    return Math.min(row.qty, held);
};

const summary = computed(() => {
    const need = parts.value.reduce((sum, row) => sum + row.qty, 0);
    const held = parts.value.reduce((sum, row) => sum + have(row), 0);

    return {
        need,
        missing: need - held,
        percent: need === 0 ? 100 : Math.floor((held / need) * 100),
    };
});

const figureSummary = computed(() => {
    const need = figures.value.reduce((sum, row) => sum + row.qty, 0);
    const held = figures.value.reduce((sum, row) => sum + haveFigure(row), 0);

    return { need, held };
});

const query = computed(() => chosen.value.map((id) => `figures[]=${encodeURIComponent(id)}`).join('&'));

const exports = computed(() =>
    exportItems(`${base.value}/export?scope=${scope.value}${query.value ? `&${query.value}` : ''}`, ['wanted', 'inventory', 'bsx']),
);

const planHref = computed(() => url(`${base.value}/plan${query.value ? `?${query.value}` : ''}`));

/** Что произойдёт, словами, — до того, как это произойдёт. */
function add() {
    const taken = parts.value.reduce((sum, row) => sum + Math.min(row.qty, row.available.loose), 0);
    const need = parts.value.reduce((sum, row) => sum + row.qty, 0);
    const figuresTaken = figures.value
        .filter((row) => chosen.value.includes(row.id))
        .reduce((sum, row) => sum + Math.min(row.qty, row.available.loose), 0);

    const message = t('pick.add_confirm', {
        name: props.item.name,
        taken: tChoice('pick.units', taken),
        lots: lots.value,
        figures: `${figuresTaken} / ${figureSummary.value.need}`,
        missing: tChoice('pick.units', need - taken),
    });

    if (! window.confirm(message)) {
        return;
    }

    pending = { figures: [...chosen.value] };
    modal.hide();
}

function onHidden() {
    if (pending) {
        const data = pending;

        pending = null;
        router.post(url(`${base.value}/add`), data);
    }
}

onMounted(() => {
    modal = new Modal(el.value);
    el.value.addEventListener('hidden.bs.modal', onHidden);
});

onBeforeUnmount(() => {
    el.value?.removeEventListener('hidden.bs.modal', onHidden);
    modal?.dispose();
});
</script>

<template>
    <div ref="el" class="modal fade" tabindex="-1" aria-labelledby="pickTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="pickTitle" class="modal-title fs-5">
                        <i class="mdi mdi-basket-check-outline me-1"></i>
                        {{ t('pick.title') }}
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" :aria-label="t('parts.cancel')"></button>
                </div>

                <!-- Переключатель, итог и выгрузки — над списком и вне прокрутки:
                     список длинный, а смотрят на итог. -->
                <div class="border-bottom px-3 py-2 d-flex flex-wrap align-items-center gap-2">
                    <div class="btn-group btn-group-sm" role="group">
                        <template v-for="option in SCOPES" :key="option.value">
                            <input
                                :id="`pickScope-${option.value}`"
                                v-model="scope"
                                type="radio"
                                class="btn-check"
                                name="pickScope"
                                :value="option.value"
                            />
                            <label class="btn btn-outline-primary" :for="`pickScope-${option.value}`">
                                {{ t(option.label) }}
                            </label>
                        </template>
                    </div>

                    <div v-if="loaded" class="d-flex flex-wrap gap-1 small">
                        <span class="badge text-bg-light border">{{ t('pick.need') }}: {{ summary.need }}</span>
                        <span class="badge" :class="summary.missing ? 'text-bg-warning' : 'text-bg-light border'">
                            {{ t('pick.missing') }}: {{ summary.missing }}
                        </span>
                        <span class="badge" :class="summary.percent === 100 ? 'text-bg-success' : 'text-bg-light border'">
                            {{ t('pick.at_hand') }}: {{ summary.percent }}%
                        </span>
                    </div>

                    <div v-if="loaded" class="ms-auto d-flex gap-2">
                        <a :href="planHref" class="btn btn-sm btn-outline-secondary text-nowrap" download>
                            <i class="mdi mdi-file-delimited-outline"></i>
                            <span class="ms-1 d-none d-sm-inline">{{ t('pick.plan') }}</span>
                        </a>
                        <ExportButton :items="exports" />
                    </div>
                </div>

                <div class="modal-body p-0">
                    <p v-if="loading && ! loaded" class="text-body-secondary p-3 mb-0">
                        <span class="spinner-border spinner-border-sm me-1"></span>
                        {{ t('pick.loading') }}
                    </p>

                    <p v-else-if="loaded && ! parts.length && ! figures.length" class="text-body-secondary p-3 mb-0">
                        {{ t('pick.nothing') }}
                    </p>

                    <template v-else-if="loaded">
                        <template v-if="figures.length">
                            <h3 class="fs-6 px-3 pt-3 mb-1">{{ t('pick.figures') }}</h3>
                            <p class="form-text px-3 mb-2">{{ t('pick.figures_hint') }}</p>

                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-3">
                                    <thead>
                                        <tr>
                                            <th style="width: 2.5rem"></th>
                                            <th style="width: 4rem"></th>
                                            <th class="text-nowrap">{{ t('lot.item') }}</th>
                                            <th class="text-nowrap text-end">{{ t('pick.need') }}</th>
                                            <th class="text-nowrap text-end">{{ t('pick.in_collection') }}</th>
                                            <th class="text-nowrap text-end">{{ t('pick.missing') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="row in figures"
                                            :key="row.id"
                                            :class="{ 'table-warning': haveFigure(row) < row.qty }"
                                        >
                                            <td class="text-center">
                                                <!-- Отметить можно только то, что есть в
                                                     свободных: брать фигурку из другого
                                                     набора добавление не станет. -->
                                                <input
                                                    v-model="chosen"
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    :value="row.id"
                                                    :disabled="row.available.loose === 0"
                                                    :aria-label="row.name"
                                                />
                                            </td>
                                            <td>
                                                <ItemImage
                                                    type="M"
                                                    :id="row.id"
                                                    :color-id="row.image_color_id"
                                                    :alt="row.name"
                                                    zoom
                                                />
                                            </td>
                                            <td>
                                                <span class="line-clamp-2" :title="row.name">{{ row.name }}</span>
                                                <span class="badge text-bg-light mt-1">{{ row.id }}</span>
                                            </td>
                                            <td class="text-end fw-semibold">{{ row.qty }}</td>
                                            <td class="text-end">{{ row.available[scope] }}</td>
                                            <td class="text-end">
                                                {{ row.qty - haveFigure(row) || '' }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>

                        <template v-if="parts.length">
                            <h3 v-if="figures.length" class="fs-6 px-3 mb-2">{{ t('pick.parts') }}</h3>

                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th style="width: 4rem"></th>
                                            <th class="text-nowrap">{{ t('lot.item') }}</th>
                                            <th class="text-nowrap">{{ t('lot.color') }}</th>
                                            <th class="text-nowrap text-end">{{ t('pick.need') }}</th>
                                            <th class="text-nowrap text-end">{{ t('pick.in_collection') }}</th>
                                            <th class="text-nowrap text-end">{{ t('pick.missing') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="row in parts" :key="row.key" :class="{ 'table-warning': have(row) < row.qty }">
                                            <td>
                                                <ItemImage
                                                    type="P"
                                                    :id="row.id"
                                                    :color-id="row.color_id"
                                                    :alt="row.name"
                                                    :zoom-subtitle="row.color_name"
                                                    zoom
                                                />
                                            </td>
                                            <td>
                                                <span class="line-clamp-2" :title="row.name">{{ row.name }}</span>
                                                <span class="badge text-bg-light mt-1">{{ row.id }}</span>
                                            </td>
                                            <td><ColorDot :rgb="row.color_rgb" :name="row.color_name" /></td>
                                            <td class="text-end fw-semibold">{{ row.qty }}</td>
                                            <td class="text-end">{{ row.available[scope] }}</td>
                                            <td class="text-end">{{ row.qty - have(row) || '' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </template>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        {{ t('parts.cancel') }}
                    </button>
                    <button type="button" class="btn btn-primary" :disabled="! loaded" @click="add">
                        <i class="mdi mdi-plus"></i>
                        {{ t('pick.add') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
