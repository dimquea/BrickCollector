<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import LotBadges from '@/Components/LotBadges.vue';
import { t } from '@/i18n';

const props = defineProps({
    lots: { type: Array, required: true },
});

const parts = computed(() => props.lots.filter((lot) => lot.type === 'P'));
</script>

<template>
    <!-- Horizontal scroll, not a collapsing layout: the columns are all
         meaningful and the service has to work on a phone. -->
    <div v-if="parts.length" class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 4rem"></th>
                    <th class="text-nowrap">{{ t('lot.item') }}</th>
                    <th class="text-nowrap">{{ t('lot.color') }}</th>
                    <th class="text-nowrap text-end">{{ t('lot.qty') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="lot in parts" :key="`${lot.id}/${lot.color_id}/${lot.match_id}`">
                    <td>
                        <Link :href="`/catalog/P/${encodeURIComponent(lot.id)}`">
                            <ItemImage type="P" :id="lot.id" :color-id="lot.color_id" :alt="lot.name" />
                        </Link>
                    </td>
                    <td>
                        <Link :href="`/catalog/P/${encodeURIComponent(lot.id)}`" class="text-decoration-none">
                            <span class="line-clamp-2" :title="lot.name">{{ lot.name }}</span>
                        </Link>
                        <div class="d-flex align-items-center gap-1 mt-1">
                            <span class="badge text-bg-light">{{ lot.id }}</span>
                            <LotBadges :lot="lot" />
                        </div>
                    </td>
                    <td><ColorDot :rgb="lot.color_rgb" :name="lot.color_name" /></td>
                    <td class="text-end fw-semibold">{{ lot.qty }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
