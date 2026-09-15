<script setup>
import axios from 'axios';
import { url } from '@/support/base';
import { computed, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import EntryMetaFields from '@/Components/EntryMetaFields.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import { notify } from '@/support/toasts';
import { t } from '@/i18n';

/**
 * Импорт из файла BrickLink XML.
 *
 * Между «выбрать файл» и «создать записи» стоит показ: человек видит, что
 * разобралось, снимает лишнее и только потом соглашается. До нажатия в базе не
 * появляется ничего.
 *
 * Общие сведения сверху применяются к каждой созданной записи, а «три точки» в
 * строке открывают те же поля для одной позиции. Пустое поле строки означает
 * «как сверху», а не «пусто»: иначе, открыв строку ради цены, человек молча
 * стёр бы у неё источник.
 */
const props = defineProps({
    dictionaries: { type: Object, required: true },
    currency: { type: String, default: 'RUB' },
    limit: { type: Number, default: 1000 },
    assemblies: { type: Array, default: () => [] },
});

const rows = ref([]);
const total = ref(0);
const parsed = ref(false);
const busy = ref(false);
const picker = ref(null);
const destination = ref('collection');

// Куда именно в сборку: «new» — завести новую, иначе номер существующей.
// Список держим у себя, а не читаем из свойств: сборка, заведённая импортом,
// должна сразу оказаться в нём на следующий раз.
const assemblyTarget = ref('new');
const assemblyList = ref([...props.assemblies]);

// Имя файла без расширения — имя будущей сборки: перечень деталей для MOC
// обычно и назван по модели.
const fileName = ref('');

const assemblyOptions = computed(() => [
    { value: 'new', label: t('import.new_assembly') },
    ...assemblyList.value.map((assembly) => ({ value: assembly.id, label: assembly.name })),
]);

function blank() {
    return {
        acquired_at: '',
        price: '',
        source_id: null,
        storage_id: null,
        note: '',
        status_ids: [],
        tag_ids: [],
    };
}

const shared = reactive(blank());

const toCollection = computed(() => destination.value === 'collection');

const toAssembly = computed(() => destination.value === 'assembly');

/**
 * Можно ли отметить строку.
 *
 * В коллекцию идёт только то, что она умеет держать; в желаемое — всё, что
 * знает справочник: хотеть можно и то, у чего нет своего раздела; в сборку —
 * одни детали, из них её и собирают.
 */
function usable(row) {
    if (destination.value === 'wishlist') {
        return row.known;
    }

    if (toAssembly.value) {
        return row.known && row.type === 'P';
    }

    return row.holdable;
}

/** Почему строку нельзя отметить: ответ зависит от того, куда её хотят деть. */
function reason(row) {
    if (! row.known) {
        return t('import.unknown');
    }

    return toAssembly.value ? t('import.parts_only') : t('import.not_holdable');
}

const addLabel = computed(() => {
    if (busy.value) {
        return t('import.adding');
    }

    if (toAssembly.value) {
        return t('import.add_assembly');
    }

    return toCollection.value ? t('import.add') : t('import.add_wishlist');
});

function resultMessage(data) {
    if (toAssembly.value) {
        return data.assembly
            ? t('import.assembled', { name: data.assembly.name, added: data.added, skipped: data.skipped })
            : t('import.nothing_assembled', { skipped: data.skipped });
    }

    return toCollection.value
        ? t('import.result', { created: data.created, skipped: data.skipped })
        : t('import.wished_result', { wished: data.wished, skipped: data.skipped });
}

const selected = computed(() => rows.value.filter((row) => row.take && usable(row)));

const allTaken = computed({
    get: () => rows.value.some((row) => row.take) && rows.value.every((row) => ! usable(row) || row.take),
    set: (value) => rows.value.forEach((row) => {
        if (usable(row)) {
            row.take = value;
        }
    }),
});

async function choose(event) {
    const file = event.target.files?.[0];

    if (! file) {
        return;
    }

    busy.value = true;

    fileName.value = file.name.replace(/\.[^.]+$/, '');

    const body = new FormData();
    body.append('file', file);

    try {
        const { data } = await axios.post(url('/import/parse'), body);

        // Запасные детали, альтернативы и парные видны, но не отмечены: это не
        // то, чем владеют, и молча завести их в коллекцию значило бы повторить
        // двойной счёт, из которого мы только что выбирались.
        rows.value = data.rows.map((row) => ({
            ...row,
            take: row.holdable && ! row.is_extra && ! row.is_alternate && ! row.is_counterpart,
            open: false,
            // Примечание из файла — это и есть заметка об этой позиции, и место
            // ей в записи, а не только в подсказке. Строка с примечанием
            // получает свои поля сразу, с уже вписанной заметкой: её видно,
            // можно поправить, и общая заметка ей уступает — частное описание
            // точнее общего. Строки без примечания берут общее.
            meta: row.remarks ? { ...blank(), note: row.remarks } : null,
        }));

        total.value = data.total;
        destination.value = data.wanted ? 'wishlist' : 'collection';
        parsed.value = true;
    } catch (reason) {
        notify(reason.response?.data?.message ?? t('import.failed'));
    } finally {
        busy.value = false;
    }
}

/** Разворачивает строку, заводя ей поля при первом открытии. */
function toggleRow(row) {
    row.meta ??= blank();
    row.open = ! row.open;
}

/**
 * Приводит поля к тому виду, в каком их ждёт сервер.
 *
 * Пустое становится null — по нему построчное уступает общему. Цена уходит в
 * минимальных единицах: дробей приложение не знает нигде, и превращение живёт в
 * единственном месте, где человек их вводит.
 */
function payload(meta) {
    return {
        acquired_at: meta.acquired_at || null,
        price: meta.price === '' ? null : Math.round(Number(meta.price) * 100),
        source_id: meta.source_id ? Number(meta.source_id) : null,
        storage_id: meta.storage_id ? Number(meta.storage_id) : null,
        note: meta.note || null,
        // Пустой список — это «не задавали», а не «снять все теги сверху».
        tag_ids: meta.tag_ids.length ? meta.tag_ids : null,
    };
}

async function submit() {
    if (! selected.value.length) {
        notify(t('import.nothing_ticked'));

        return;
    }

    busy.value = true;

    try {
        const { data } = await axios.post(url('/import'), {
            destination: destination.value,
            meta: toCollection.value ? payload(shared) : null,
            // Выбранная сборка приходит из селекта строкой; «новая» и пустое
            // поле означают одно — завести.
            assembly_id: toAssembly.value && assemblyTarget.value && assemblyTarget.value !== 'new'
                ? Number(assemblyTarget.value)
                : null,
            assembly_name: toAssembly.value ? fileName.value : null,
            rows: selected.value.map((row) => ({
                type: row.type,
                id: row.id,
                color_id: row.color_id,
                qty: row.qty,
                meta: row.meta && toCollection.value ? payload(row.meta) : null,
            })),
        });

        notify(resultMessage(data), 'success', 6000);

        // Заведённая импортом сборка сразу встаёт в список — на случай, если
        // следом придёт второй файл той же модели.
        if (data.assembly && ! assemblyList.value.some((assembly) => assembly.id === data.assembly.id)) {
            assemblyList.value = [...assemblyList.value, data.assembly]
                .sort((left, right) => left.name.localeCompare(right.name));
        }

        rows.value = [];
        parsed.value = false;

        if (picker.value) {
            picker.value.value = '';
        }
    } catch (reason) {
        notify(reason.response?.data?.message ?? t('errors.save_failed'));
    } finally {
        busy.value = false;
    }
}

/** Сколько записей выйдет из позиции: у набора и фигурки — по одной на штуку. */
const copies = (row) => (row.type === 'P' ? 1 : row.qty);
</script>

<template>
    <Head :title="t('import.title')" />

    <AppLayout>
        <h1 class="h4 mb-3">{{ t('import.title') }}</h1>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <p class="text-body-secondary small">{{ t('import.hint') }}</p>

                <label for="importFile" class="form-label">{{ t('import.file') }}</label>
                <input
                    id="importFile"
                    ref="picker"
                    type="file"
                    accept=".xml,text/xml,application/xml"
                    class="form-control"
                    :disabled="busy"
                    @change="choose"
                />

                <template v-if="parsed">
                    <fieldset class="mt-3">
                        <legend class="form-label fs-6">{{ t('import.destination') }}</legend>
                        <!-- Сборку выбирают тут же, справа: «в сборку» без ответа
                             «в какую» — только половина вопроса. -->
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <div class="btn-group" role="group">
                                <input
                                    id="toCollection"
                                    v-model="destination"
                                    type="radio"
                                    class="btn-check"
                                    value="collection"
                                />
                                <label class="btn btn-outline-primary" for="toCollection">
                                    {{ t('import.to_collection') }}
                                </label>

                                <input
                                    id="toWishlist"
                                    v-model="destination"
                                    type="radio"
                                    class="btn-check"
                                    value="wishlist"
                                />
                                <label class="btn btn-outline-primary" for="toWishlist">
                                    {{ t('import.to_wishlist') }}
                                </label>

                                <input
                                    id="toAssembly"
                                    v-model="destination"
                                    type="radio"
                                    class="btn-check"
                                    value="assembly"
                                />
                                <label class="btn btn-outline-primary" for="toAssembly">
                                    {{ t('import.to_assembly') }}
                                </label>
                            </div>

                            <div v-if="toAssembly" style="min-width: 14rem">
                                <label for="assemblyTarget" class="visually-hidden">
                                    {{ t('import.assembly_target') }}
                                </label>
                                <SearchSelect
                                    id="assemblyTarget"
                                    v-model="assemblyTarget"
                                    :options="assemblyOptions"
                                />
                            </div>
                        </div>
                    </fieldset>

                    <!-- У желания нет ни цены, ни места хранения: спрашивать о
                         них значило бы обещать, что они сохранятся. -->
                    <div v-if="toCollection" class="mt-4">
                        <h2 class="h6">{{ t('import.shared') }}</h2>
                        <p class="text-body-secondary small">{{ t('import.shared_hint') }}</p>

                        <EntryMetaFields
                            :form="shared"
                            :dictionaries="dictionaries"
                            :currency="currency"
                            prefix="shared"
                        />
                    </div>
                </template>
            </div>
        </div>

        <template v-if="parsed && rows.length">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                <button type="button" class="btn btn-primary" :disabled="busy" @click="submit">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>
                    {{ addLabel }}
                </button>
                <span class="text-body-secondary small">{{ selected.length }} / {{ rows.length }}</span>
                <span v-if="total > rows.length" class="text-body-secondary small">
                    {{ t('import.capped', { shown: rows.length, total }) }}
                </span>
            </div>

            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 2.5rem">
                                    <input
                                        v-model="allTaken"
                                        class="form-check-input"
                                        type="checkbox"
                                        :title="t('import.select_all')"
                                        :aria-label="t('import.select_all')"
                                    />
                                </th>
                                <th style="width: 4rem"></th>
                                <th class="text-nowrap">{{ t('lot.item') }}</th>
                                <th class="text-nowrap">{{ t('lot.color') }}</th>
                                <th class="text-nowrap text-end">{{ t('lot.qty') }}</th>
                                <th style="width: 5rem"></th>
                            </tr>
                        </thead>

                        <tbody v-for="(row, index) in rows" :key="`${row.type}/${row.id}/${index}`">
                            <tr :class="{ 'opacity-50': ! usable(row) }">
                                <td>
                                    <input
                                        v-model="row.take"
                                        class="form-check-input"
                                        type="checkbox"
                                        :disabled="! usable(row)"
                                        :title="usable(row) ? '' : (reason(row))"
                                    />
                                </td>

                                <td>
                                    <ItemImage
                                        :type="row.type"
                                        :id="row.id"
                                        :color-id="row.color_id ?? row.image_color_id"
                                        :alt="row.name ?? row.id"
                                    />
                                </td>

                                <td>
                                    <div class="line-clamp-2">{{ row.name ?? row.id }}</div>
                                    <div class="d-flex flex-wrap align-items-center gap-1 mt-1">
                                        <span class="badge text-bg-light border">{{ row.id }}</span>
                                        <span v-if="row.is_extra" class="badge text-bg-light" :title="t('lot.extra_hint')">
                                            {{ t('lot.extra') }}
                                        </span>
                                        <span v-if="row.is_alternate" class="badge text-bg-info" :title="t('lot.alternate_hint')">
                                            {{ t('lot.alternate') }}
                                        </span>
                                        <span
                                            v-if="row.is_counterpart"
                                            class="badge text-bg-secondary"
                                            :title="t('lot.counterpart_hint')"
                                        >
                                            {{ t('lot.counterpart') }}
                                        </span>
                                        <!-- Сам текст примечания в таблицу не
                                             помещается и не нужен: важно, что
                                             оно есть. -->
                                        <i
                                            v-if="row.remarks"
                                            class="mdi mdi-note-text-outline text-body-secondary"
                                            :title="`${t('import.remarks')}: ${row.remarks}`"
                                        ></i>
                                        <span v-if="! usable(row)" class="badge text-bg-warning">
                                            {{ reason(row) }}
                                        </span>
                                    </div>
                                </td>

                                <td>
                                    <ColorDot v-if="row.color_name" :rgb="row.color_rgb" :name="row.color_name" />
                                </td>

                                <td class="text-end">
                                    <span class="fw-semibold">{{ row.qty }}</span>
                                    <div
                                        v-if="toCollection && copies(row) > 1"
                                        class="small text-body-secondary text-nowrap"
                                    >
                                        {{ t('import.copies', { count: copies(row) }) }}
                                    </div>
                                </td>

                                <td class="text-end">
                                    <button
                                        v-if="toCollection"
                                        type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        :title="t('import.row_meta')"
                                        :aria-label="t('import.row_meta')"
                                        @click="toggleRow(row)"
                                    >
                                        <i class="mdi mdi-dots-horizontal"></i>
                                    </button>
                                </td>
                            </tr>

                            <tr v-if="row.open && toCollection">
                                <td colspan="6" class="bg-body-tertiary">
                                    <h3 class="h6">{{ t('import.row_meta') }}</h3>
                                    <p class="text-body-secondary small">{{ t('import.row_hint') }}</p>

                                    <!-- Контейнер здесь обязателен: у строки
                                         Bootstrap отрицательные поля по краям,
                                         и внутри ячейки они вылезают за её
                                         ширину — таблица получает
                                         горизонтальную прокрутку на ровном
                                         месте. Контейнер их гасит своими
                                         отступами. -->
                                    <div class="container-fluid">
                                        <EntryMetaFields
                                            :form="row.meta"
                                            :dictionaries="dictionaries"
                                            :currency="currency"
                                            :prefix="`row-${index}`"
                                        />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
                <button type="button" class="btn btn-primary" :disabled="busy" @click="submit">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>
                    {{ addLabel }}
                </button>
                <span class="text-body-secondary small">{{ selected.length }} / {{ rows.length }}</span>
            </div>
        </template>

        <p v-else-if="parsed" class="text-body-secondary">{{ t('import.nothing') }}</p>
    </AppLayout>
</template>
