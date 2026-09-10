<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ItemImage from '@/Components/ItemImage.vue';
import LotBadges from '@/Components/LotBadges.vue';
import OwnedLotsTable from '@/Components/OwnedLotsTable.vue';
import LostQuantityInput from '@/Components/LostQuantityInput.vue';
import { t } from '@/i18n';

/**
 * A minifigure or subset of an owned copy, with its own contents.
 * Recursive: a boxed series holds packets, each holding a minifigure.
 */
const props = defineProps({
    lot: { type: Object, required: true },
    domId: { type: String, required: true },
});

const emit = defineEmits(['saved']);

/**
 * Anything missing further down.
 *
 * Without this the group header says something is missing and every
 * minifigure below it looks fine, leaving the person to open all eight to
 * find the one with a part gone. Kept separate from the node's own loss:
 * "this figure is gone" and "this figure is short a part" are different
 * things to be told.
 */
const lostInside = computed(() => {
    const walk = (lots) =>
        lots.reduce(
            (sum, lot) => sum + (lot.counts ? lot.lost_qty : 0) + walk(lot.children ?? []),
            0,
        );

    return walk(props.lot.children ?? []);
});
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
                <span
                    v-if="lot.lost_qty > 0"
                    class="badge text-bg-warning flex-shrink-0"
                    :title="t('lot.lost')"
                >
                    <i class="mdi mdi-alert-outline"></i> {{ lot.lost_qty }}
                </span>
                <span
                    v-if="lostInside > 0"
                    class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle flex-shrink-0"
                    :title="t('lot.lost_inside')"
                >
                    <i class="mdi mdi-alert-circle-outline"></i> {{ lostInside }}
                </span>
                <LotBadges :lot="lot" />
            </button>
        </h2>

        <div :id="domId" class="accordion-collapse collapse">
            <div class="accordion-body">
                <div class="d-flex flex-wrap align-items-end gap-3 mb-3">
                    <div>
                        <label class="form-label small mb-1">{{ t('lot.lost') }}</label>
                        <div style="width: 6rem">
                            <LostQuantityInput :lot="lot" small @saved="emit('saved', $event)" />
                        </div>
                    </div>
                    <!-- A minifigure has a section of its own, so it opens
                         there; a subset has none and falls back to the
                         catalog. -->
                    <Link
                        :href="lot.type === 'M'
                            ? `/minifigures/${encodeURIComponent(lot.item_id)}`
                            : `/catalog/${lot.type}/${encodeURIComponent(lot.item_id)}`"
                        class="btn btn-sm btn-outline-secondary"
                    >
                        {{ t('item.open') }}
                    </Link>
                </div>

                <OwnedLotsTable :lots="lot.children" @saved="emit('saved', $event)" />

                <div v-if="lot.children.some((c) => c.type !== 'P')" class="accordion mt-3">
                    <OwnedNode
                        v-for="(child, index) in lot.children.filter((c) => c.type !== 'P')"
                        :key="child.id"
                        :lot="child"
                        :dom-id="`${domId}-${index}`"
                        @saved="emit('saved', $event)"
                    />
                </div>

                <p v-if="!lot.children.length" class="text-body-secondary mb-0">
                    {{ t('item.no_inventory') }}
                </p>
            </div>
        </div>
    </div>
</template>
