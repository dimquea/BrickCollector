<script setup>
import { url } from '@/support/base';
import { computed, reactive, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Link from '@/Components/AppLink.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SortControl from '@/Components/SortControl.vue';
import ExportButton from '@/Components/ExportButton.vue';
import { debounce } from '@/support/debounce';
import { t, tChoice } from '@/i18n';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    sort: { type: Object, default: () => ({}) },
    parts: { type: Object, required: true },
    colours: { type: Array, default: () => [] },
    // Только теги, проставленные партиям: остальные ничего не нашли бы.
    tags: { type: Array, default: () => [] },
});

const sortOptions = computed(() => [
    { value: 'name', label: t('sort.name') },
    { value: 'id', label: t('sort.id') },
    { value: 'total', label: t('sort.total') },
]);

// Обычный порядок раздела — по названию — показывается пустым полем.
const chosenSort = props.sort.by === 'name' ? null : props.sort.by ?? null;

const form = reactive({
    q: props.filters.q ?? '',
    color_id: props.filters.color_id ?? null,
    placement: props.filters.placement ?? null,
    tag_id: props.filters.tag_id ?? null,
    lost: Boolean(props.filters.lost),
    sort: chosenSort,
    dir: props.sort.dir ?? 'asc',
});

// Тег принадлежит партии, поэтому спрашивать о нём есть смысл только там, где
// речь о партиях. При смене места выбранный тег сбрасывается — иначе он остался
// бы в адресе невидимым и молча сужал список.
const tagOptions = computed(() => props.tags.map((tag) => ({ value: tag.id, label: tag.name })));

const showsTags = computed(() => form.placement === 'loose' && props.tags.length > 0);

watch(() => form.placement, (placement) => {
    if (placement !== 'loose') {
        form.tag_id = null;
    }
});

function submit() {
    router.get(url('/parts'), clean(), { preserveState: true, preserveScroll: true, replace: true });
}

/** Empty filters stay out of the URL so a bare /parts is a clean link. */
function clean() {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== null && value !== '' && value !== false),
    );

    if (query.dir === 'asc') {
        delete query.dir;
    }

    return query;
}

// Выгружается ровно то, что на экране: адрес собирается из тех же фильтров.
const exportHref = computed(() => {
    const query = new URLSearchParams(clean()).toString();

    return query ? `/parts/export?${query}` : '/parts/export';
});

// «Есть недостача» меняет смысл выгрузки: не «вот что у меня есть», а «вот
// чего мне не хватает».
const exportMode = computed(() => (form.lost ? 'wanted' : 'inventory'));

function reset() {
    Object.assign(form, {
        q: '', color_id: null, placement: null, tag_id: null, lost: false, sort: null, dir: 'asc',
    });
}

watch(() => form.q, debounce(submit, 300));
watch(() => [form.color_id, form.placement, form.tag_id, form.lost, form.sort, form.dir], submit);

/** A badge or a colour dot in the table is also a way to narrow the list. */
function filterByArticle(itemId) {
    form.q = itemId;
}

function filterByColour(colorId) {
    form.color_id = colorId;
}

// С точкой цвета, как в таблице ниже: названия вроде «Dark Bluish Gray» и
// «Light Bluish Gray» на слух не различить, а на вид — сразу.
const colourOptions = computed(() =>
    props.colours.map((colour) => ({ value: colour.id, label: colour.name, rgb: colour.rgb })),
);

// Where a part sits. What is missing is not a place — a brick can be gone from
// a set and still lie loose in a drawer — so it filters alongside, not instead.
const placements = [
    { value: 'set', label: 'parts.in_sets' },
    { value: 'minifigure', label: 'parts.in_minifigures' },
    { value: 'loose', label: 'parts.loose' },
    { value: 'assembly', label: 'assembly.in_assemblies' },
];

const placementOptions = computed(() => placements.map((place) => ({ value: place.value, label: t(place.label) })));

const href = (part) => `/parts/${encodeURIComponent(part.item_id)}/${part.color_id}`;
</script>

<template>
    <Head :title="t('nav.parts')" />

    <AppLayout>
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
            <h1 class="h4 mb-0">{{ t('nav.parts') }}</h1>
            <span class="text-body-secondary small">{{ tChoice('parts.found', parts.total) }}</span>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-lg-4">
                        <label for="q" class="form-label">{{ t('catalog.query') }}</label>
                        <input
                            id="q"
                            v-model="form.q"
                            type="search"
                            class="form-control"
                            :placeholder="t('parts.query_hint')"
                        />
                    </div>

                    <div v-if="colours.length > 1" class="col-12 col-lg-3">
                        <label for="colour" class="form-label">{{ t('lot.color') }}</label>
                        <SearchSelect
                            id="colour"
                            v-model="form.color_id"
                            :options="colourOptions"
                            :placeholder="t('catalog.any')"
                        />
                    </div>

                    <div class="col-12 col-lg-3">
                        <label for="placement" class="form-label">{{ t('parts.placement') }}</label>
                        <SearchSelect
                            id="placement"
                            v-model="form.placement"
                            :options="placementOptions"
                            :placeholder="t('catalog.any')"
                        />
                    </div>

                    <!-- Тег принадлежит партии, поэтому спрашивать о нём есть
                         смысл только там, где речь о партиях. -->
                    <div v-if="showsTags" class="col-12 col-lg-3">
                        <label for="tag" class="form-label">{{ t('collection.tags') }}</label>
                        <SearchSelect
                            id="tag"
                            v-model="form.tag_id"
                            :options="tagOptions"
                            :placeholder="t('catalog.any')"
                        />
                    </div>

                    <!-- На узком экране столбцы встают друг под другом, и
                         кнопка оказывалась посреди фильтров. Там она уходит в
                         конец; на широком остаётся на месте. -->
                    <div class="col-12 col-lg-2 d-flex align-items-end order-last order-lg-0">
                        <button type="button" class="btn btn-outline-secondary w-100 text-nowrap" @click="reset">
                            {{ t('catalog.reset') }}
                        </button>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input id="missing" v-model="form.lost" class="form-check-input" type="checkbox" />
                            <label class="form-check-label" for="missing">{{ t('parts.missing') }}</label>
                        </div>
                        <div class="form-text">{{ t('parts.missing_hint') }}</div>
                    </div>

                    <!-- Порядок — отдельной строкой внизу: он отвечает не на
                         «что показать», а на «в каком виде». Разрыв явный,
                         иначе строка встала бы в остаток предыдущей. -->
                    <div class="w-100"></div>
                    <div class="col-12 col-lg-4 d-flex align-items-end gap-2">
                        <ExportButton :href="exportHref" :mode="exportMode" />
                        <SortControl
                            v-model:by="form.sort"
                            v-model:dir="form.dir"
                            :options="sortOptions"
                            class="flex-grow-1"
                        />
                    </div>
                </div>
            </div>
        </div>

        <p v-if="parts.data.length === 0" class="text-body-secondary">
            {{ t('collection.empty') }}
            <Link href="/catalog">{{ t('collection.empty_hint') }}</Link>
        </p>

        <div v-else class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width: 4rem"></th>
                            <th class="text-nowrap">{{ t('lot.item') }}</th>
                            <th class="text-nowrap">{{ t('lot.color') }}</th>
                            <th class="text-nowrap text-end">{{ t('parts.total') }}</th>
                            <!-- Строка должна сходиться: итог — это сумма мест,
                                 и без «отдельно» и «в сборках» две детали из
                                 восьми оказывались нигде. -->
                            <th class="text-nowrap text-end" :title="t('parts.in_sets_hint')">
                                {{ t('parts.in_sets') }}
                            </th>
                            <th class="text-nowrap text-end">{{ t('parts.in_minifigures') }}</th>
                            <th class="text-nowrap text-end">{{ t('parts.loose') }}</th>
                            <th class="text-nowrap text-end">{{ t('assembly.in_assemblies') }}</th>
                            <th class="text-nowrap text-end">{{ t('item.extras') }}</th>
                            <th class="text-nowrap text-end">{{ t('collection.lost') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="part in parts.data"
                            :key="`${part.item_id}/${part.color_id}`"
                            :class="{ 'table-warning': part.lost > 0 }"
                        >
                            <td>
                                <Link :href="href(part)">
                                    <ItemImage
                                        type="P"
                                        :id="part.item_id"
                                        :color-id="part.color_id"
                                        :alt="part.name"
                                    />
                                </Link>
                            </td>

                            <td>
                                <Link
                                    :href="href(part)"
                                    class="text-decoration-none line-clamp-2"
                                    :title="part.name"
                                >
                                    {{ part.name }}
                                </Link>
                                <div class="d-flex align-items-center gap-1 mt-1">
                                    <button
                                        type="button"
                                        class="badge text-bg-light border"
                                        :title="t('parts.filter_by_article')"
                                        @click="filterByArticle(part.item_id)"
                                    >
                                        {{ part.item_id }}
                                    </button>
                                    <span
                                        v-if="part.has_extra"
                                        class="badge text-bg-light border"
                                        :title="t('lot.extra_hint')"
                                    >
                                        {{ t('lot.extra') }}
                                    </span>
                                    <span
                                        v-if="part.has_alternate"
                                        class="badge text-bg-info"
                                        :title="t('lot.alternate_hint')"
                                    >
                                        {{ t('lot.alternate') }}
                                    </span>
                                    <span
                                        v-if="part.has_counterpart"
                                        class="badge text-bg-secondary"
                                        :title="t('lot.counterpart_hint')"
                                    >
                                        {{ t('lot.counterpart') }}
                                    </span>
                                </div>
                            </td>

                            <td>
                                <button
                                    type="button"
                                    class="btn btn-link p-0 text-decoration-none text-body"
                                    :title="t('parts.filter_by_colour')"
                                    @click="filterByColour(part.color_id)"
                                >
                                    <ColorDot :rgb="part.color_rgb" :name="part.color_name" />
                                </button>
                            </td>

                            <td class="text-end fw-semibold">{{ part.total }}</td>
                            <td class="text-end">{{ part.in_sets || '' }}</td>
                            <td class="text-end">{{ part.in_minifigures || '' }}</td>
                            <td class="text-end">{{ part.loose || '' }}</td>
                            <td class="text-end">{{ part.in_assemblies || '' }}</td>
                            <td class="text-end text-body-secondary">{{ part.spares || '' }}</td>
                            <td class="text-end text-warning-emphasis">{{ part.lost || '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <nav v-if="parts.last_page > 1" class="mt-4">
            <ul class="pagination justify-content-center flex-wrap">
                <li
                    v-for="link in parts.links"
                    :key="link.label"
                    class="page-item"
                    :class="{ active: link.active, disabled: !link.url }"
                >
                    <Link v-if="link.url" class="page-link" :href="link.url" preserve-scroll v-html="link.label" />
                    <span v-else class="page-link" v-html="link.label" />
                </li>
            </ul>
        </nav>
    </AppLayout>
</template>
