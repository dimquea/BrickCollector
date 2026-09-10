<script setup>
import { Link } from '@inertiajs/vue3';
import ItemImage from '@/Components/ItemImage.vue';
import LotBadges from '@/Components/LotBadges.vue';
import PartsTable from '@/Components/PartsTable.vue';
import { t } from '@/i18n';

/**
 * One minifigure or subset inside an item, with its own contents.
 * Recursive: a boxed series holds packets, each holding a minifigure.
 */
defineProps({
    lot: { type: Object, required: true },
    domId: { type: String, required: true },
});
</script>

<template>
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button
                class="accordion-button collapsed gap-2"
                type="button"
                data-bs-toggle="collapse"
                :data-bs-target="`#${domId}`"
            >
                <span style="width: 3rem" class="flex-shrink-0">
                    <ItemImage :type="lot.type" :id="lot.id" :color-id="lot.image_color_id" :alt="lot.name" />
                </span>
                <span class="badge text-bg-secondary flex-shrink-0">{{ lot.id }}</span>
                <span class="text-truncate">{{ lot.name }}</span>
                <span v-if="lot.qty > 1" class="badge text-bg-light flex-shrink-0">&times;{{ lot.qty }}</span>
                <LotBadges :lot="lot" />
            </button>
        </h2>

        <div :id="domId" class="accordion-collapse collapse">
            <div class="accordion-body">
                <Link
                    :href="`/catalog/${lot.type}/${encodeURIComponent(lot.id)}`"
                    class="btn btn-sm btn-outline-secondary mb-3"
                >
                    {{ t('item.open') }}
                </Link>

                <PartsTable :lots="lot.children" />

                <div v-if="lot.children.some((c) => c.type !== 'P')" class="accordion mt-3">
                    <InventoryNode
                        v-for="(child, index) in lot.children.filter((c) => c.type !== 'P')"
                        :key="`${child.type}/${child.id}/${index}`"
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
