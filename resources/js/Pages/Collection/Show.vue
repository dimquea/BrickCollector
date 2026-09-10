<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import OwnedLotsTable from '@/Components/OwnedLotsTable.vue';
import OwnedNode from '@/Components/OwnedNode.vue';
import { t } from '@/i18n';

const props = defineProps({
    entry: { type: Object, required: true },
    contents: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
});

const page = usePage();
const flash = computed(() => page.props.flash);

const nested = computed(() => props.contents.filter((lot) => lot.type !== 'P'));
const hasParts = computed(() => props.contents.some((lot) => lot.type === 'P'));

function remove() {
    if (window.confirm(t('collection.remove_confirm', { name: props.entry.name }))) {
        router.delete(`/collection/${props.entry.id}`);
    }
}
</script>

<template>
    <Head :title="`${entry.item_id} ${entry.name}`" />

    <AppLayout>
        <div v-if="flash?.message" class="alert alert-success d-flex align-items-center gap-2">
            <i class="mdi mdi-check-circle-outline"></i>
            <span>{{ flash.message }}</span>
        </div>

        <nav class="mb-3">
            <Link href="/collection" class="text-decoration-none">
                <i class="mdi mdi-arrow-left"></i> {{ t('collection.title') }}
            </Link>
        </nav>

        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span class="badge text-bg-secondary">{{ entry.item_id }}</span>
                        <span class="text-truncate" :title="entry.name">{{ entry.name }}</span>
                    </div>

                    <ItemImage
                        :type="entry.type"
                        :id="entry.item_id"
                        :color-id="entry.image_color_id"
                        :alt="entry.name"
                        class="card-img-top p-3"
                    />

                    <ul class="list-group list-group-flush">
                        <li v-if="entry.year" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('catalog.year') }}</span>
                            <span>{{ entry.year }}</span>
                        </li>
                        <li v-if="entry.theme" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('catalog.theme') }}</span>
                            <span class="text-end">{{ entry.theme }}</span>
                        </li>
                    </ul>

                    <div class="card-footer d-flex flex-wrap gap-1 align-items-center">
                        <span
                            v-if="entry.flag_incomplete"
                            class="badge text-bg-warning"
                            :title="t('collection.incomplete_hint')"
                        >
                            <i class="mdi mdi-alert-outline"></i> {{ t('collection.incomplete') }}
                        </span>
                        <span
                            v-if="entry.flag_missing_figs"
                            class="badge text-bg-warning"
                            :title="t('collection.missing_figs_hint')"
                        >
                            <i class="mdi mdi-account-alert-outline"></i> {{ t('collection.missing_figs') }}
                        </span>
                        <Link
                            :href="`/catalog/${entry.type}/${encodeURIComponent(entry.item_id)}`"
                            class="btn btn-sm btn-link ms-auto p-0"
                        >
                            {{ t('collection.open_in_catalog') }}
                        </Link>
                    </div>
                </div>

                <div class="accordion mt-4">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button
                                class="accordion-button collapsed text-danger"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#dangerZone"
                            >
                                <i class="mdi mdi-alert-outline me-2"></i>
                                {{ t('collection.danger_zone') }}
                            </button>
                        </h2>
                        <div id="dangerZone" class="accordion-collapse collapse">
                            <div class="accordion-body">
                                <button type="button" class="btn btn-outline-danger w-100" @click="remove">
                                    {{ t('collection.remove') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="card shadow-sm mb-4">
                    <div class="card-body d-flex flex-wrap gap-4">
                        <div>
                            <div class="fs-4 fw-semibold">{{ totals.parts }}</div>
                            <div class="text-body-secondary small">{{ t('item.parts') }}</div>
                        </div>
                        <div v-if="totals.minifigures">
                            <div class="fs-4 fw-semibold">{{ totals.minifigures }}</div>
                            <div class="text-body-secondary small">{{ t('item.minifigures') }}</div>
                        </div>
                        <div v-if="totals.extras">
                            <div class="fs-4 fw-semibold">{{ totals.extras }}</div>
                            <div class="text-body-secondary small">{{ t('lot.extra') }}</div>
                        </div>
                        <div v-if="totals.lost">
                            <div class="fs-4 fw-semibold text-warning">{{ totals.lost }}</div>
                            <div class="text-body-secondary small">{{ t('collection.lost') }}</div>
                        </div>
                    </div>
                </div>

                <div v-if="nested.length" class="accordion mb-4">
                    <OwnedNode
                        v-for="(lot, index) in nested"
                        :key="lot.id"
                        :lot="lot"
                        :dom-id="`owned-${index}`"
                    />
                </div>

                <div v-if="hasParts" class="card shadow-sm">
                    <div class="card-header">{{ t('item.parts') }}</div>
                    <div class="card-body p-0">
                        <OwnedLotsTable :lots="contents" />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
