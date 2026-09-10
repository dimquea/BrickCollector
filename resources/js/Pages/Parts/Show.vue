<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import Link from '@/Components/AppLink.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import { t } from '@/i18n';

const props = defineProps({
    part: { type: Object, required: true },
    inEntries: { type: Array, default: () => [] },
    inMinifigures: { type: Array, default: () => [] },
    otherColours: { type: Array, default: () => [] },
    missingIn: { type: Array, default: () => [] },
});

// A part is not something one owns a copy of, so there is nothing to edit
// here — no purchase date, no source, no note. Only where it is.
// An empty tab is not information: it invites a click that shows nothing.
// Only the ones with something behind them are offered.
const tabs = [
    { key: 'entries', label: 'parts.tab_entries', count: props.inEntries.length },
    { key: 'minifigures', label: 'parts.tab_minifigures', count: props.inMinifigures.length },
    { key: 'colours', label: 'parts.tab_colours', count: props.otherColours.length },
    { key: 'missing', label: 'parts.tab_missing', count: props.missingIn.length },
].filter((tab) => tab.count > 0);

const active = ref(tabs[0]?.key ?? null);

const entryHref = (row) => `/sets/${row.entry_id}`;
const colourHref = (row) => `/parts/${encodeURIComponent(row.item_id)}/${row.color_id}`;
</script>

<template>
    <Head :title="`${part.item_id} ${part.name}`" />

    <AppLayout>
        <nav class="mb-3">
            <Link href="/parts" class="text-decoration-none">
                <i class="mdi mdi-arrow-left"></i> {{ t('nav.parts') }}
            </Link>
        </nav>

        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-start gap-2">
                        <span class="badge text-bg-secondary flex-shrink-0 mt-1">{{ part.item_id }}</span>
                        <!-- A detail page has room; the name is not cut here. -->
                        <span>{{ part.name }}</span>
                    </div>

                    <Link :href="`/catalog/P/${encodeURIComponent(part.item_id)}`">
                        <ItemImage
                            type="P"
                            :id="part.item_id"
                            :color-id="part.color_id"
                            :alt="part.name"
                            class="card-img-top p-3"
                        />
                    </Link>

                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('lot.color') }}</span>
                            <ColorDot :rgb="part.color_rgb" :name="part.color_name" />
                        </li>
                        <li v-if="part.in_sets" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('parts.in_sets') }}</span>
                            <span>{{ part.in_sets }}</span>
                        </li>
                        <li v-if="part.in_minifigures" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('parts.in_minifigures') }}</span>
                            <span>{{ part.in_minifigures }}</span>
                        </li>
                        <li v-if="part.loose" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('parts.loose') }}</span>
                            <span>{{ part.loose }}</span>
                        </li>
                        <li v-if="part.spares" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('item.extras') }}</span>
                            <span>{{ part.spares }}</span>
                        </li>
                    </ul>

                    <div class="card-footer d-flex flex-wrap gap-1 align-items-center">
                        <span class="badge text-bg-success">
                            {{ t('parts.total') }}: {{ part.total }}
                        </span>
                        <span v-if="part.lost" class="badge text-bg-warning">
                            <i class="mdi mdi-alert-outline"></i>
                            {{ t('collection.lost') }}: {{ part.lost }}
                        </span>
                        <Link
                            :href="`/catalog/P/${encodeURIComponent(part.item_id)}`"
                            class="btn btn-sm btn-link ms-auto p-0"
                        >
                            {{ t('collection.open_in_catalog') }}
                        </Link>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <ul v-if="tabs.length" class="nav nav-tabs">
                    <li v-for="tab in tabs" :key="tab.key" class="nav-item">
                        <button
                            type="button"
                            class="nav-link d-flex align-items-center gap-2"
                            :class="{ active: active === tab.key }"
                            @click="active = tab.key"
                        >
                            {{ t(tab.label) }}
                            <span class="badge text-bg-secondary">{{ tab.count }}</span>
                        </button>
                    </li>
                </ul>

                <div class="card shadow-sm" :class="{ 'border-top-0 rounded-top-0': tabs.length }">
                    <div class="card-body p-0">
                        <p v-if="!tabs.length" class="text-body-secondary p-3 mb-0">
                            {{ t('parts.tab_empty') }}
                        </p>

                        <table v-else class="table table-sm align-middle mb-0">
                            <tbody>
                                <template v-if="active === 'entries'">
                                    <tr v-for="row in inEntries" :key="row.entry_id">
                                        <td style="width: 4rem">
                                            <Link :href="entryHref(row)">
                                                <ItemImage
                                                    :type="row.type"
                                                    :id="row.item_id"
                                                    :color-id="row.image_color_id"
                                                    :alt="row.name"
                                                />
                                            </Link>
                                        </td>
                                        <td>
                                            <Link :href="entryHref(row)" class="text-decoration-none">
                                                {{ row.name }}
                                            </Link>
                                            <div><span class="badge text-bg-light border">{{ row.item_id }}</span></div>
                                        </td>
                                        <td class="text-end fw-semibold">{{ row.qty }}</td>
                                    </tr>
                                </template>

                                <template v-else-if="active === 'minifigures'">
                                    <tr v-for="row in inMinifigures" :key="row.item_id">
                                        <td style="width: 4rem">
                                            <Link :href="`/catalog/M/${encodeURIComponent(row.item_id)}`">
                                                <ItemImage
                                                    type="M"
                                                    :id="row.item_id"
                                                    :color-id="row.image_color_id"
                                                    :alt="row.name"
                                                />
                                            </Link>
                                        </td>
                                        <td>
                                            <Link
                                                :href="`/catalog/M/${encodeURIComponent(row.item_id)}`"
                                                class="text-decoration-none"
                                            >
                                                {{ row.name }}
                                            </Link>
                                            <div><span class="badge text-bg-light border">{{ row.item_id }}</span></div>
                                        </td>
                                        <td class="text-end fw-semibold">{{ row.qty }}</td>
                                    </tr>
                                </template>

                                <template v-else-if="active === 'colours'">
                                    <tr v-for="row in otherColours" :key="row.color_id">
                                        <td style="width: 4rem">
                                            <Link :href="colourHref(row)">
                                                <ItemImage
                                                    type="P"
                                                    :id="row.item_id"
                                                    :color-id="row.color_id"
                                                    :alt="row.name"
                                                />
                                            </Link>
                                        </td>
                                        <td>
                                            <Link :href="colourHref(row)" class="text-decoration-none">
                                                <ColorDot :rgb="row.color_rgb" :name="row.color_name" />
                                            </Link>
                                        </td>
                                        <td class="text-end fw-semibold">{{ row.total }}</td>
                                    </tr>
                                </template>

                                <template v-else>
                                    <tr v-for="row in missingIn" :key="row.entry_id" class="table-warning">
                                        <td style="width: 4rem">
                                            <Link :href="entryHref(row)">
                                                <ItemImage
                                                    :type="row.type"
                                                    :id="row.item_id"
                                                    :color-id="row.image_color_id"
                                                    :alt="row.name"
                                                />
                                            </Link>
                                        </td>
                                        <td>
                                            <Link :href="entryHref(row)" class="text-decoration-none">
                                                {{ row.name }}
                                            </Link>
                                            <div><span class="badge text-bg-light border">{{ row.item_id }}</span></div>
                                        </td>
                                        <td class="text-end fw-semibold">{{ row.lost }} / {{ row.qty }}</td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
