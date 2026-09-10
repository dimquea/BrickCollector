<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import LotBadges from '@/Components/LotBadges.vue';
import { locale, t } from '@/i18n';

const props = defineProps({
    figure: { type: Object, required: true },
    parts: { type: Array, default: () => [] },
    inEntries: { type: Array, default: () => [] },
    missingIn: { type: Array, default: () => [] },
    copies: { type: Array, default: () => [] },
});

// Only tabs with something behind them. An empty one offers a click that
// leads nowhere.
const tabs = [
    { key: 'parts', label: 'minifigures.tab_parts', count: props.parts.length },
    { key: 'entries', label: 'minifigures.tab_entries', count: props.inEntries.length },
    { key: 'copies', label: 'minifigures.tab_copies', count: props.copies.length },
    { key: 'missing', label: 'minifigures.tab_missing', count: props.missingIn.length },
].filter((tab) => tab.count > 0);

const active = ref(tabs[0]?.key ?? null);

const money = (minor) =>
    minor === null
        ? ''
        : new Intl.NumberFormat(locale.value, { minimumFractionDigits: 2 }).format(minor / 100);
</script>

<template>
    <Head :title="`${figure.item_id} ${figure.name}`" />

    <AppLayout>
        <nav class="mb-3">
            <Link href="/minifigures" class="text-decoration-none">
                <i class="mdi mdi-arrow-left"></i> {{ t('nav.minifigures') }}
            </Link>
        </nav>

        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-start gap-2">
                        <span class="badge text-bg-secondary flex-shrink-0 mt-1">{{ figure.item_id }}</span>
                        <!-- A detail page has room; the name is not cut here. -->
                        <span>{{ figure.name }}</span>
                    </div>

                    <ItemImage
                        type="M"
                        :id="figure.item_id"
                        :color-id="figure.image_color_id"
                        :alt="figure.name"
                        class="card-img-top p-3"
                    />

                    <ul class="list-group list-group-flush">
                        <li v-if="figure.year" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('catalog.year') }}</span>
                            <span>{{ figure.year }}</span>
                        </li>
                        <li v-if="figure.theme" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('catalog.theme') }}</span>
                            <span class="text-end">{{ figure.theme }}</span>
                        </li>
                        <li v-if="figure.in_sets" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('minifigures.in_sets') }}</span>
                            <span>{{ figure.in_sets }}</span>
                        </li>
                        <li v-if="figure.loose" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('minifigures.loose') }}</span>
                            <span>{{ figure.loose }}</span>
                        </li>
                    </ul>

                    <div class="card-footer d-flex flex-wrap gap-1 align-items-center">
                        <span class="badge text-bg-success">
                            {{ t('parts.total') }}: {{ figure.total }}
                        </span>
                        <span v-if="figure.lost" class="badge text-bg-warning">
                            <i class="mdi mdi-alert-outline"></i>
                            {{ t('collection.lost') }}: {{ figure.lost }}
                        </span>
                        <Link
                            :href="`/catalog/M/${encodeURIComponent(figure.item_id)}`"
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

                        <div v-else class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <tbody>
                                    <template v-if="active === 'parts'">
                                        <tr v-for="part in parts" :key="`${part.item_id}/${part.color_id}`">
                                            <td style="width: 4rem">
                                                <Link :href="`/parts/${encodeURIComponent(part.item_id)}/${part.color_id}`">
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
                                                    :href="`/parts/${encodeURIComponent(part.item_id)}/${part.color_id}`"
                                                    class="text-decoration-none"
                                                >
                                                    <span class="line-clamp-2" :title="part.name">{{ part.name }}</span>
                                                </Link>
                                                <div class="d-flex align-items-center gap-1 mt-1">
                                                    <span class="badge text-bg-light border">{{ part.item_id }}</span>
                                                    <LotBadges :lot="part" />
                                                </div>
                                            </td>
                                            <td><ColorDot :rgb="part.color_rgb" :name="part.color_name" /></td>
                                            <td class="text-end fw-semibold">{{ part.qty }}</td>
                                        </tr>
                                    </template>

                                    <template v-else-if="active === 'entries'">
                                        <tr v-for="row in inEntries" :key="row.entry_id">
                                            <td style="width: 4rem">
                                                <Link :href="`/sets/${row.entry_id}`">
                                                    <ItemImage
                                                        :type="row.type"
                                                        :id="row.item_id"
                                                        :color-id="row.image_color_id"
                                                        :alt="row.name"
                                                    />
                                                </Link>
                                            </td>
                                            <td>
                                                <Link :href="`/sets/${row.entry_id}`" class="text-decoration-none">
                                                    <span class="line-clamp-2">{{ row.name }}</span>
                                                </Link>
                                                <div><span class="badge text-bg-light border">{{ row.item_id }}</span></div>
                                            </td>
                                            <td class="text-end fw-semibold">{{ row.qty }}</td>
                                        </tr>
                                    </template>

                                    <template v-else-if="active === 'copies'">
                                        <tr v-for="copy in copies" :key="copy.entry_id">
                                            <td style="width: 4rem">
                                                <Link :href="`/minifigures/copy/${copy.entry_id}`">
                                                    <ItemImage
                                                        type="M"
                                                        :id="figure.item_id"
                                                        :color-id="figure.image_color_id"
                                                        :alt="figure.name"
                                                    />
                                                </Link>
                                            </td>
                                            <td>
                                                <Link
                                                    :href="`/minifigures/copy/${copy.entry_id}`"
                                                    class="text-decoration-none"
                                                >
                                                    {{ copy.acquired_at ?? t('minifigures.copy_untitled') }}
                                                </Link>
                                                <div class="d-flex flex-wrap gap-1 mt-1">
                                                    <span
                                                        v-for="tag in copy.tags"
                                                        :key="tag.name"
                                                        class="badge"
                                                        :class="`text-bg-${tag.color}`"
                                                    >
                                                        {{ tag.name }}
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="text-end">{{ money(copy.price) }}</td>
                                        </tr>
                                    </template>

                                    <template v-else>
                                        <tr v-for="row in missingIn" :key="row.entry_id" class="table-warning">
                                            <td style="width: 4rem">
                                                <Link :href="`/sets/${row.entry_id}`">
                                                    <ItemImage
                                                        :type="row.type"
                                                        :id="row.item_id"
                                                        :color-id="row.image_color_id"
                                                        :alt="row.name"
                                                    />
                                                </Link>
                                            </td>
                                            <td>
                                                <Link :href="`/sets/${row.entry_id}`" class="text-decoration-none">
                                                    <span class="line-clamp-2">{{ row.name }}</span>
                                                </Link>
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
        </div>
    </AppLayout>
</template>
