<script setup>
import { Link, router } from '@inertiajs/vue3';
import ItemImage from '@/Components/ItemImage.vue';
import LotBadges from '@/Components/LotBadges.vue';
import OwnedLotsTable from '@/Components/OwnedLotsTable.vue';
import { t } from '@/i18n';

/**
 * A minifigure or subset of an owned copy, with its own contents.
 * Recursive: a boxed series holds packets, each holding a minifigure.
 */
defineProps({
    lot: { type: Object, required: true },
    domId: { type: String, required: true },
});

function setLost(lot, event) {
    const value = Number(event.target.value);

    if (!Number.isNaN(value) && value !== lot.lost_qty) {
        router.patch(
            `/collection/lot/${lot.id}`,
            { lost_qty: value },
            { preserveScroll: true, preserveState: false },
        );
    }
}
</script>

<template>
    <div class="accordion-item" :class="{ 'opacity-50': !lot.counts }">
        <h2 class="accordion-header">
            <button
                class="accordion-button collapsed gap-2"
                type="button"
                data-bs-toggle="collapse"
                :data-bs-target="`#${domId}`"
            >
                <span style="width: 3rem" class="flex-shrink-0">
                    <ItemImage :type="lot.type" :id="lot.item_id" :color-id="lot.image_color_id" :alt="lot.name" />
                </span>
                <span class="badge text-bg-secondary flex-shrink-0">{{ lot.item_id }}</span>
                <span class="text-truncate">{{ lot.name }}</span>
                <span v-if="lot.qty > 1" class="badge text-bg-light flex-shrink-0">&times;{{ lot.qty }}</span>
                <span v-if="lot.lost_qty > 0" class="badge text-bg-warning flex-shrink-0">
                    <i class="mdi mdi-alert-outline"></i> {{ lot.lost_qty }}
                </span>
                <LotBadges :lot="lot" />
            </button>
        </h2>

        <div :id="domId" class="accordion-collapse collapse">
            <div class="accordion-body">
                <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
                    <div>
                        <label class="form-label small mb-1">{{ t('lot.lost') }}</label>
                        <input
                            type="number"
                            class="form-control form-control-sm text-end"
                            style="width: 6rem"
                            min="0"
                            :max="lot.qty"
                            :value="lot.lost_qty"
                            @change="setLost(lot, $event)"
                        />
                    </div>
                    <Link
                        :href="`/catalog/${lot.type}/${encodeURIComponent(lot.item_id)}`"
                        class="btn btn-sm btn-outline-secondary"
                    >
                        {{ t('item.open') }}
                    </Link>
                </div>

                <OwnedLotsTable :lots="lot.children" />

                <div v-if="lot.children.some((c) => c.type !== 'P')" class="accordion mt-3">
                    <OwnedNode
                        v-for="(child, index) in lot.children.filter((c) => c.type !== 'P')"
                        :key="child.id"
                        :lot="child"
                        :dom-id="`${domId}-${index}`"
                    />
                </div>

                <p v-if="!lot.children.length" class="text-body-secondary mb-0">
                    {{ t('item.no_inventory') }}
                </p>
            </div>
        </div>
    </div>
</template>
