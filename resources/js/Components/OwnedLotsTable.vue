<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import LotBadges from '@/Components/LotBadges.vue';
import LostQuantityInput from '@/Components/LostQuantityInput.vue';
import { t } from '@/i18n';

/**
 * Parts of an owned copy, with the count of what went missing.
 *
 * The input writes on change rather than through a save button: a person
 * marking losses works through a pile of bricks and would lose track of an
 * unsaved form.
 */
const props = defineProps({
    lots: { type: Array, required: true },
});

const emit = defineEmits(['saved']);

const parts = computed(() => props.lots.filter((lot) => lot.type === 'P'));
</script>

<template>
    <div v-if="parts.length" class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th style="width: 4rem"></th>
                    <th>{{ t('lot.item') }}</th>
                    <th>{{ t('lot.color') }}</th>
                    <th class="text-end">{{ t('lot.qty') }}</th>
                    <th class="text-end" style="width: 7rem">{{ t('lot.lost') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="lot in parts"
                    :key="lot.id"
                    :class="{ 'table-warning': lot.lost_qty > 0, 'opacity-50': !lot.counts }"
                >
                    <td>
                        <Link :href="`/catalog/P/${encodeURIComponent(lot.item_id)}`">
                            <ItemImage type="P" :id="lot.item_id" :color-id="lot.color_id" :alt="lot.name" />
                        </Link>
                    </td>
                    <td>
                        <Link :href="`/catalog/P/${encodeURIComponent(lot.item_id)}`" class="text-decoration-none">
                            {{ lot.name }}
                        </Link>
                        <div class="d-flex align-items-center gap-1 mt-1">
                            <span class="badge text-bg-light">{{ lot.item_id }}</span>
                            <LotBadges :lot="lot" />
                        </div>
                    </td>
                    <td><ColorDot :rgb="lot.color_rgb" :name="lot.color_name" /></td>
                    <td class="text-end fw-semibold">{{ lot.qty }}</td>
                    <td>
                        <LostQuantityInput :lot="lot" small @saved="emit('saved', $event)" />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
