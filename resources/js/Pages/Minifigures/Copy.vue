<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import OwnedLotsTable from '@/Components/OwnedLotsTable.vue';
import EntryMetaForm from '@/Components/EntryMetaForm.vue';
import { t } from '@/i18n';

/**
 * One standalone minifigure a person owns.
 *
 * Its own page because the metadata belongs to the copy, not to the figure:
 * two of the same figure can be bought on different days for different money.
 *
 * The tree of such an entry begins with a row for the figure itself; the
 * server hands over its children, since a page showing the figure inside
 * itself would be nonsense.
 *
 * There is no "lost" count for the figure as a whole here. Inside a set that
 * count answers "the set is missing its figure"; for a figure owned on its
 * own, not having it means it is not in the collection, and the answer is to
 * remove the copy. Individual parts of it can still go missing.
 */
const props = defineProps({
    entry: { type: Object, required: true },
    parts: { type: Array, default: () => [] },
    meta: { type: Object, required: true },
    dictionaries: { type: Object, required: true },
    currency: { type: String, default: 'RUB' },
});

const page = usePage();
const flash = computed(() => page.props.flash);

function remove() {
    if (window.confirm(t('collection.remove_confirm', { name: props.entry.name }))) {
        router.delete(`/minifigures/copy/${props.entry.id}`);
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
            <Link :href="`/minifigures/${encodeURIComponent(entry.item_id)}`" class="text-decoration-none">
                <i class="mdi mdi-arrow-left"></i> {{ entry.name }}
            </Link>
        </nav>

        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-start gap-2">
                        <span class="badge text-bg-secondary flex-shrink-0 mt-1">{{ entry.item_id }}</span>
                        <span>{{ entry.name }}</span>
                    </div>

                    <ItemImage
                        type="M"
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
                        <Link
                            :href="`/catalog/M/${encodeURIComponent(entry.item_id)}`"
                            class="btn btn-sm btn-link ms-auto p-0"
                        >
                            {{ t('collection.open_in_catalog') }}
                        </Link>
                    </div>
                </div>

                <div class="accordion mt-4">
                    <EntryMetaForm
                        :entry-id="entry.id"
                        :endpoint="`/minifigures/copy/${entry.id}`"
                        :meta="meta"
                        :dictionaries="dictionaries"
                        :currency="currency"
                    />

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
                <div v-if="parts.length" class="card shadow-sm">
                    <div class="card-header">{{ t('item.parts') }}</div>
                    <div class="card-body p-0">
                        <OwnedLotsTable :lots="parts" />
                    </div>
                </div>

                <p v-else class="text-body-secondary">{{ t('item.no_inventory') }}</p>
            </div>
        </div>
    </AppLayout>
</template>
