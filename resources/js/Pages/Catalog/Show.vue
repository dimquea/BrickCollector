<script setup>
import { url } from '@/support/base';
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Link from '@/Components/AppLink.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import PartsTable from '@/Components/PartsTable.vue';
import InventoryNode from '@/Components/InventoryNode.vue';
import ExternalLinks from '@/Components/ExternalLinks.vue';
import AddPartDialog from '@/Components/AddPartDialog.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import { t, tChoice } from '@/i18n';

const props = defineProps({
    links: { type: Array, default: () => [] },
    item: { type: Object, required: true },
    inventory: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    elementCodes: { type: Array, default: () => [] },
    // A part only: what the add dialog offers.
    colours: { type: Array, default: () => [] },
    lots: { type: Array, default: () => [] },
    // What this item is part of — null when nothing lists it.
    parents: { type: Object, default: null },
});

const isPart = computed(() => props.item.type === 'P');

const adding = ref(false);
const dialog = ref(null);

// A part needs a colour and a quantity, and may go onto a lot already held,
// so it asks first. Anything else is added as one whole thing straight away.
function addToCollection(colorId = null) {
    if (isPart.value) {
        dialog.value.open(colorId);

        return;
    }

    adding.value = true;
    router.post(
        url(`/catalog/${props.item.type}/${encodeURIComponent(props.item.id)}/add`),
        {},
        { onFinish: () => (adding.value = false) },
    );
}

const nested = computed(() => props.inventory.filter((lot) => lot.type !== 'P'));
const hasParts = computed(() => props.inventory.some((lot) => lot.type === 'P'));

/*
 * "Part of" is paged on the server: a common brick is in tens of thousands of
 * inventories, so the tab, the colour and the page all live in the address and
 * each change is a visit. The state stays with the page, which is what makes a
 * link to "this brick, in black" work.
 */
const parentsHref = (kind, colour, page = 1) => {
    const query = new URLSearchParams();

    if (kind) {
        query.set('in', kind);
    }

    if (colour !== null && colour !== undefined && colour !== '') {
        query.set('in_color', colour);
    }

    if (page > 1) {
        query.set('in_page', page);
    }

    const search = query.toString();

    return `/catalog/${props.item.type}/${encodeURIComponent(props.item.id)}${search ? `?${search}` : ''}`;
};

const colourFilter = ref(props.parents?.colour ?? null);

// The server may answer with another tab — a colour can empty the open one —
// so the control follows what came back rather than what was clicked.
watch(() => props.parents?.colour ?? null, (colour) => (colourFilter.value = colour));

watch(colourFilter, (colour) => {
    const current = props.parents?.colour ?? null;

    if (String(colour ?? '') === String(current ?? '')) {
        return;
    }

    router.get(url(parentsHref(props.parents?.kind, colour)), {}, {
        preserveScroll: true,
        preserveState: true,
    });
});

const parentColourOptions = computed(() =>
    (props.parents?.colours ?? []).map((colour) => ({ value: colour.id, label: colour.name })),
);

const parentRows = computed(() => props.parents?.rows?.data ?? []);
</script>

<template>
    <Head :title="`${item.id} ${item.name}`" />

    <AppLayout>
        <nav class="mb-3">
            <Link href="/catalog" class="text-decoration-none">
                <i class="mdi mdi-arrow-left"></i> {{ t('catalog.title') }}
            </Link>
        </nav>

        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-start gap-2">
                        <span class="badge text-bg-secondary flex-shrink-0 mt-1">{{ item.id }}</span>
                        <!-- A detail page has room; the name is not cut here. -->
                        <span>{{ item.name }}</span>
                    </div>

                    <ItemImage
                        :type="item.type"
                        :id="item.id"
                        :color-id="item.image_color_id"
                        :alt="item.name"
                        class="card-img-top p-3"
                    />

                    <div class="card-body">
                        <button
                            type="button"
                            class="btn btn-primary w-100"
                            :disabled="adding"
                            @click="addToCollection()"
                        >
                            <i class="mdi mdi-plus"></i>
                            {{ t('collection.add') }}
                        </button>
                    </div>

                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('item.type') }}</span>
                            <span>{{ item.type_name }}</span>
                        </li>
                        <li v-if="item.year" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('catalog.year') }}</span>
                            <span>{{ item.year }}</span>
                        </li>
                        <li v-if="item.theme" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('catalog.theme') }}</span>
                            <Link
                                :href="`/catalog?theme_id=${item.theme_id}`"
                                class="text-end text-decoration-none"
                            >
                                {{ item.theme }}
                            </Link>
                        </li>
                        <li v-if="item.weight" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('item.weight') }}</span>
                            <span>{{ item.weight }} {{ t('item.grams') }}</span>
                        </li>
                    </ul>
                </div>

                <ExternalLinks :links="links" />
            </div>

            <div class="col-12 col-lg-8">
                <div v-if="item.has_inventory" class="card shadow-sm mb-4">
                    <div class="card-body d-flex flex-wrap gap-3">
                        <div v-if="totals.parts">
                            <div class="fs-4 fw-semibold">{{ totals.parts }}</div>
                            <div class="text-body-secondary small">{{ t('item.parts') }}</div>
                        </div>
                        <div v-if="totals.lots">
                            <div class="fs-4 fw-semibold">{{ totals.lots }}</div>
                            <div class="text-body-secondary small">{{ t('item.lots') }}</div>
                        </div>
                        <div v-if="totals.minifigures">
                            <div class="fs-4 fw-semibold">{{ totals.minifigures }}</div>
                            <div class="text-body-secondary small">{{ t('item.minifigures') }}</div>
                        </div>
                        <div v-if="totals.subsets">
                            <div class="fs-4 fw-semibold">{{ totals.subsets }}</div>
                            <div class="text-body-secondary small">{{ t('item.subsets') }}</div>
                        </div>
                        <div v-if="totals.extras">
                            <div class="fs-4 fw-semibold text-warning">{{ totals.extras }}</div>
                            <div class="text-body-secondary small">{{ t('item.extras') }}</div>
                        </div>
                    </div>
                </div>

                <div v-if="nested.length" class="accordion mb-4">
                    <InventoryNode
                        v-for="(lot, index) in nested"
                        :key="`${lot.type}/${lot.id}/${index}`"
                        :lot="lot"
                        :dom-id="`lot-${index}`"
                    />
                </div>

                <div v-if="hasParts" class="card shadow-sm mb-4">
                    <div class="card-header">{{ t('item.parts') }}</div>
                    <div class="card-body p-0">
                        <PartsTable :lots="inventory" />
                    </div>
                </div>

                <!-- What this item is part of, read out of the inventory
                     backwards. Tabs are kinds of parent; a part can also be
                     narrowed to one colour. -->
                <div v-if="parents" class="card shadow-sm mb-4">
                    <div class="card-header d-flex flex-wrap align-items-center gap-2">
                        <span>{{ t('item.appears_in') }}</span>

                        <div v-if="parents.colours.length" class="ms-auto" style="min-width: 12rem">
                            <SearchSelect
                                id="parentColour"
                                v-model="colourFilter"
                                :options="parentColourOptions"
                                :placeholder="t('catalog.any')"
                            />
                        </div>
                    </div>

                    <ul v-if="parents.kinds.length" class="nav nav-tabs px-2 pt-2">
                        <li v-for="kind in parents.kinds" :key="kind.code" class="nav-item">
                            <Link
                                class="nav-link d-flex align-items-center gap-2"
                                :class="{ active: kind.code === parents.kind }"
                                :href="parentsHref(kind.code, parents.colour)"
                                preserve-scroll
                            >
                                {{ kind.name }}
                                <span class="badge text-bg-secondary">{{ kind.count }}</span>
                            </Link>
                        </li>
                    </ul>

                    <p v-if="!parentRows.length" class="text-body-secondary p-3 mb-0">
                        {{ parents.colour === null ? t('item.appears_none') : t('item.appears_none_colour') }}
                    </p>

                    <div v-else class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <tbody>
                                <tr v-for="row in parentRows" :key="`${row.type}/${row.id}`">
                                    <td style="width: 4rem">
                                        <Link :href="`/catalog/${row.type}/${encodeURIComponent(row.id)}`">
                                            <ItemImage
                                                :type="row.type"
                                                :id="row.id"
                                                :color-id="row.image_color_id"
                                                :alt="row.name"
                                            />
                                        </Link>
                                    </td>
                                    <td>
                                        <Link
                                            :href="`/catalog/${row.type}/${encodeURIComponent(row.id)}`"
                                            class="text-decoration-none"
                                        >
                                            <span class="line-clamp-2" :title="row.name">{{ row.name }}</span>
                                        </Link>
                                        <div class="d-flex align-items-center gap-1 mt-1">
                                            <span class="badge text-bg-light border">{{ row.id }}</span>
                                            <span v-if="row.year" class="badge text-bg-light">{{ row.year }}</span>
                                        </div>
                                    </td>
                                    <td class="text-end fw-semibold">&times;{{ row.qty }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="parents.rows?.last_page > 1" class="card-body">
                        <nav>
                            <ul class="pagination pagination-sm justify-content-center flex-wrap mb-2">
                                <li
                                    v-for="link in parents.rows.links"
                                    :key="link.label"
                                    class="page-item"
                                    :class="{ active: link.active, disabled: !link.url }"
                                >
                                    <Link
                                        v-if="link.url"
                                        class="page-link"
                                        :href="link.url"
                                        preserve-scroll
                                        v-html="link.label"
                                    />
                                    <span v-else class="page-link" v-html="link.label" />
                                </li>
                            </ul>
                        </nav>
                        <p class="form-text text-center mb-0">{{ t('item.appears_order') }}</p>
                    </div>
                </div>

                <div v-if="elementCodes.length" class="card shadow-sm">
                    <div class="card-header">{{ t('item.element_codes') }}</div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <tbody>
                                <tr v-for="code in elementCodes" :key="code.code">
                                    <td><ColorDot :rgb="code.color_rgb" :name="code.color_name" /></td>
                                    <td class="text-end"><code>{{ code.code }}</code></td>
                                    <td class="text-end" style="width: 3rem">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary py-0"
                                            :title="t('parts.add_in_colour')"
                                            :aria-label="t('parts.add_in_colour')"
                                            @click="addToCollection(code.color_id)"
                                        >
                                            <i class="mdi mdi-plus"></i>
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <p v-if="!item.has_inventory && !parents" class="text-body-secondary">
                    {{ t('item.no_inventory') }}
                </p>
            </div>
        </div>

        <AddPartDialog v-if="isPart" ref="dialog" :item="item" :colours="colours" :lots="lots" />
    </AppLayout>
</template>
