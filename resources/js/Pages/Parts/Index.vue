<script setup>
import { url } from '@/support/base';
import { computed, reactive, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Link from '@/Components/AppLink.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import { debounce } from '@/support/debounce';
import { t, tChoice } from '@/i18n';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    parts: { type: Object, required: true },
    colours: { type: Array, default: () => [] },
});

const form = reactive({
    q: props.filters.q ?? '',
    color_id: props.filters.color_id ?? null,
    placement: props.filters.placement ?? null,
});

function submit() {
    router.get(url('/parts'), clean(), { preserveState: true, preserveScroll: true, replace: true });
}

function clean() {
    return Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== null && value !== ''),
    );
}

function reset() {
    Object.assign(form, { q: '', color_id: null, placement: null });
}

watch(() => form.q, debounce(submit, 300));
watch(() => [form.color_id, form.placement], submit);

/** A badge or a colour dot in the table is also a way to narrow the list. */
function filterByArticle(itemId) {
    form.q = itemId;
}

function filterByColour(colorId) {
    form.color_id = colorId;
}

const colourOptions = computed(() =>
    props.colours.map((colour) => ({ value: colour.id, label: colour.name })),
);

const placements = [
    { value: 'set', label: 'parts.in_sets' },
    { value: 'minifigure', label: 'parts.in_minifigures' },
    { value: 'loose', label: 'parts.loose' },
    { value: 'lost', label: 'parts.lost' },
];

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
                        <select id="placement" v-model="form.placement" class="form-select">
                            <option :value="null">{{ t('catalog.any') }}</option>
                            <option v-for="place in placements" :key="place.value" :value="place.value">
                                {{ t(place.label) }}
                            </option>
                        </select>
                    </div>

                    <div class="col-12 col-lg-2 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-secondary w-100 text-nowrap" @click="reset">
                            {{ t('catalog.reset') }}
                        </button>
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
                            <th class="text-nowrap text-end">{{ t('parts.in_sets') }}</th>
                            <th class="text-nowrap text-end">{{ t('parts.in_minifigures') }}</th>
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
