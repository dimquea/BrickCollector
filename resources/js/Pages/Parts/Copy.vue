<script setup>
import { url } from '@/support/base';
import { computed, reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import Link from '@/Components/AppLink.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import OwnedLotsTable from '@/Components/OwnedLotsTable.vue';
import EntryMetaForm from '@/Components/EntryMetaForm.vue';
import EntryMetaBadges from '@/Components/EntryMetaBadges.vue';
import EntryMetaRows from '@/Components/EntryMetaRows.vue';
import EntryNote from '@/Components/EntryNote.vue';
import ExternalLinks from '@/Components/ExternalLinks.vue';
import { patchField } from '@/support/save';
import { notify } from '@/support/toasts';
import { t } from '@/i18n';

/**
 * One loose lot of a part.
 *
 * Its own page because the metadata belongs to the lot, not to the part: two
 * handfuls of the same brick can be bought on different days for different
 * money. The colour is not edited here — a lot in another colour is another
 * lot — but the quantity is.
 */
const props = defineProps({
    links: { type: Array, default: () => [] },
    entry: { type: Object, required: true },
    parts: { type: Array, default: () => [] },
    meta: { type: Object, required: true },
    dictionaries: { type: Object, required: true },
    currency: { type: String, default: 'RUB' },
});

const page = usePage();
const flash = computed(() => page.props.flash);

const meta = reactive({ ...props.meta });

function applyMeta({ meta: saved }) {
    Object.assign(meta, saved);
}

// What the card shows, and what the field holds while it is being edited.
const qty = ref(props.entry.qty);
const qtyInput = ref(props.entry.qty);
const savingQty = ref(false);

async function saveQty() {
    savingQty.value = true;

    const result = await patchField(
        `/parts/copy/${props.entry.id}/qty`,
        { qty: qtyInput.value },
        { onRevert: () => (qtyInput.value = qty.value) },
    );

    savingQty.value = false;

    if (result) {
        qty.value = result.qty;
        notify(result.message ?? t('collection.saved'), 'success', 2500);
    }
}

function remove() {
    if (window.confirm(t('collection.remove_confirm', { name: props.entry.name }))) {
        router.delete(url(`/parts/copy/${props.entry.id}`));
    }
}

const partHref = `/parts/${encodeURIComponent(props.entry.item_id)}/${props.entry.color_id}`;
</script>

<template>
    <Head :title="`${entry.item_id} ${entry.name}`" />

    <AppLayout>
        <div v-if="flash?.message" class="alert alert-success d-flex align-items-center gap-2">
            <i class="mdi mdi-check-circle-outline"></i>
            <span>{{ flash.message }}</span>
        </div>

        <nav class="mb-3">
            <Link :href="partHref" class="text-decoration-none">
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
                        type="P"
                        :id="entry.item_id"
                        :color-id="entry.color_id"
                        :alt="entry.name"
                        class="card-img-top p-3"
                    />

                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('lot.color') }}</span>
                            <ColorDot :rgb="entry.color_rgb" :name="entry.color_name" />
                        </li>
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('parts.quantity') }}</span>
                            <span>{{ qty }}</span>
                        </li>
                        <li v-if="entry.lost" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('collection.lost') }}</span>
                            <span>{{ entry.lost }}</span>
                        </li>

                        <EntryMetaRows :meta="meta" :dictionaries="dictionaries" :currency="currency" />
                    </ul>

                    <div class="card-footer d-flex flex-wrap gap-1 align-items-center">
                        <EntryMetaBadges :meta="meta" :dictionaries="dictionaries" />
                        <Link
                            :href="`/catalog/P/${encodeURIComponent(entry.item_id)}`"
                            class="btn btn-sm btn-link ms-auto p-0"
                        >
                            {{ t('collection.open_in_catalog') }}
                        </Link>
                    </div>
                </div>

                <ExternalLinks :links="links" />

                <EntryNote :note="meta.note" />

                <div class="accordion mt-4">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button
                                class="accordion-button collapsed"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#lotQty"
                            >
                                <i class="mdi mdi-counter me-2"></i>
                                {{ t('parts.quantity') }}
                            </button>
                        </h2>
                        <div id="lotQty" class="accordion-collapse collapse">
                            <div class="accordion-body">
                                <form class="d-flex gap-2" @submit.prevent="saveQty">
                                    <input
                                        v-model.number="qtyInput"
                                        type="number"
                                        :min="Math.max(1, entry.lost)"
                                        max="99999"
                                        class="form-control"
                                        :aria-label="t('parts.quantity')"
                                        required
                                    />
                                    <button type="submit" class="btn btn-primary" :disabled="savingQty">
                                        {{ t('collection.save') }}
                                    </button>
                                </form>
                                <div v-if="entry.lost" class="form-text">
                                    {{ t('parts.quantity_hint', { count: entry.lost }) }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <EntryMetaForm
                        :entry-id="entry.id"
                        :endpoint="`/parts/copy/${entry.id}`"
                        :meta="props.meta"
                        :dictionaries="dictionaries"
                        :currency="currency"
                        @saved="applyMeta"
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

            <!-- A plain brick is made of nothing; an assembly lists its pieces. -->
            <div v-if="parts.length" class="col-12 col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header">{{ t('item.parts') }}</div>
                    <div class="card-body p-0">
                        <OwnedLotsTable :lots="parts" />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
