<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import { t, tChoice } from '@/i18n';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    entries: { type: Object, required: true },
    totals: { type: Object, default: () => ({}) },
    itemTypes: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash);

function filterByType(type) {
    router.get('/collection', type ? { type } : {}, { preserveState: true, replace: true });
}

function remove(entry) {
    if (window.confirm(t('collection.remove_confirm', { name: entry.name }))) {
        router.delete(`/collection/${entry.id}`, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="t('collection.title')" />

    <AppLayout>
        <div v-if="flash?.message" class="alert alert-success d-flex align-items-center gap-2">
            <i class="mdi mdi-check-circle-outline"></i>
            <span>{{ flash.message }}</span>
        </div>

        <h1 class="h4 mb-3">{{ t('collection.title') }}</h1>

        <div class="card shadow-sm mb-4">
            <div class="card-body d-flex flex-wrap gap-4">
                <div>
                    <div class="fs-4 fw-semibold">{{ totals.entries }}</div>
                    <div class="text-body-secondary small">{{ t('collection.entries') }}</div>
                </div>
                <div>
                    <div class="fs-4 fw-semibold">{{ totals.sets }}</div>
                    <div class="text-body-secondary small">{{ t('collection.sets') }}</div>
                </div>
                <div>
                    <div class="fs-4 fw-semibold">{{ totals.parts }}</div>
                    <div class="text-body-secondary small">{{ t('item.parts') }}</div>
                </div>
                <div>
                    <div class="fs-4 fw-semibold">{{ totals.minifigures }}</div>
                    <div class="text-body-secondary small">{{ t('item.minifigures') }}</div>
                </div>
                <div v-if="totals.lost">
                    <div class="fs-4 fw-semibold text-warning">{{ totals.lost }}</div>
                    <div class="text-body-secondary small">{{ t('collection.lost') }}</div>
                </div>
            </div>
        </div>

        <div class="btn-group btn-group-sm mb-4 flex-wrap">
            <button
                type="button"
                class="btn"
                :class="!filters.type ? 'btn-primary' : 'btn-outline-secondary'"
                @click="filterByType(null)"
            >
                {{ t('catalog.any') }}
            </button>
            <button
                v-for="type in itemTypes"
                :key="type.code"
                type="button"
                class="btn"
                :class="filters.type === type.code ? 'btn-primary' : 'btn-outline-secondary'"
                @click="filterByType(type.code)"
            >
                {{ type.name }}
            </button>
        </div>

        <p v-if="entries.data.length === 0" class="text-body-secondary">
            {{ t('collection.empty') }}
            <Link href="/catalog">{{ t('collection.empty_hint') }}</Link>
        </p>

        <div class="masonry">
            <div v-for="entry in entries.data" :key="entry.id" class="card shadow-sm">
                <div class="card-header d-flex align-items-center gap-2 text-truncate">
                    <span class="badge text-bg-secondary flex-shrink-0">{{ entry.item_id }}</span>
                    <span class="text-truncate" :title="entry.name">{{ entry.name }}</span>
                </div>

                <Link :href="`/catalog/${entry.type}/${encodeURIComponent(entry.item_id)}`">
                    <ItemImage
                        :type="entry.type"
                        :id="entry.item_id"
                        :color-id="entry.image_color_id"
                        :alt="entry.name"
                        class="card-img-top p-2"
                    />
                </Link>

                <div class="card-footer d-flex flex-wrap gap-1 align-items-center">
                    <span v-if="entry.counts.parts" class="badge text-bg-light">
                        {{ tChoice('collection.parts_badge', entry.counts.parts) }}
                    </span>
                    <span v-if="entry.counts.minifigures" class="badge text-bg-light">
                        {{ tChoice('collection.figures_badge', entry.counts.minifigures) }}
                    </span>
                    <button
                        type="button"
                        class="btn btn-sm btn-link text-danger ms-auto p-0"
                        :title="t('collection.remove')"
                        @click="remove(entry)"
                    >
                        <i class="mdi mdi-delete-outline"></i>
                    </button>
                </div>
            </div>
        </div>

        <nav v-if="entries.last_page > 1" class="mt-4">
            <ul class="pagination justify-content-center flex-wrap">
                <li
                    v-for="link in entries.links"
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
