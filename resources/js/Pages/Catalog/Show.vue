<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import PartsTable from '@/Components/PartsTable.vue';
import InventoryNode from '@/Components/InventoryNode.vue';
import { t, tChoice } from '@/i18n';

const props = defineProps({
    item: { type: Object, required: true },
    inventory: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    elementCodes: { type: Array, default: () => [] },
});

const nested = computed(() => props.inventory.filter((lot) => lot.type !== 'P'));
const hasParts = computed(() => props.inventory.some((lot) => lot.type === 'P'));
</script>

<template>
    <Head :title="`${item.id} ${item.name}`" />

    <AppLayout>
        <nav class="mb-3">
            <Link href="/catalog" class="text-decoration-none">
                <i class="mdi mdi-arrow-left"></i> {{ t('catalog.title') }}
            </Link>
        </nav>

        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span class="badge text-bg-secondary">{{ item.id }}</span>
                        <span class="text-truncate" :title="item.name">{{ item.name }}</span>
                    </div>

                    <ItemImage
                        :type="item.type"
                        :id="item.id"
                        :color-id="item.image_color_id"
                        :alt="item.name"
                        class="card-img-top p-3"
                    />

                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('item.type') }}</span>
                            <span>{{ item.type_name }}</span>
                        </li>
                        <li v-if="item.year" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('catalog.year') }}</span>
                            <span>{{ item.year }}</span>
                        </li>
                        <li v-if="item.theme" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('catalog.theme') }}</span>
                            <Link
                                :href="`/catalog?theme_id=${item.theme_id}`"
                                class="text-end text-decoration-none"
                            >
                                {{ item.theme }}
                            </Link>
                        </li>
                        <li v-if="item.weight" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('item.weight') }}</span>
                            <span>{{ item.weight }} {{ t('item.grams') }}</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div v-if="item.has_inventory" class="card shadow-sm mb-4">
                    <div class="card-body d-flex flex-wrap gap-3">
                        <div v-if="totals.parts">
                            <div class="fs-4 fw-semibold">{{ totals.parts }}</div>
                            <div class="text-body-secondary small">{{ t('item.parts') }}</div>
                        </div>
                        <div v-if="totals.lots">
                            <div class="fs-4 fw-semibold">{{ totals.lots }}</div>
                            <div class="text-body-secondary small">{{ t('item.lots') }}</div>
                        </div>
                        <div v-if="totals.minifigures">
                            <div class="fs-4 fw-semibold">{{ totals.minifigures }}</div>
                            <div class="text-body-secondary small">{{ t('item.minifigures') }}</div>
                        </div>
                        <div v-if="totals.subsets">
                            <div class="fs-4 fw-semibold">{{ totals.subsets }}</div>
                            <div class="text-body-secondary small">{{ t('item.subsets') }}</div>
                        </div>
                        <div v-if="totals.extras">
                            <div class="fs-4 fw-semibold text-warning">{{ totals.extras }}</div>
                            <div class="text-body-secondary small">{{ t('lot.extra') }}</div>
                        </div>
                    </div>
                </div>

                <div v-if="nested.length" class="accordion mb-4">
                    <InventoryNode
                        v-for="(lot, index) in nested"
                        :key="`${lot.type}/${lot.id}/${index}`"
                        :lot="lot"
                        :dom-id="`lot-${index}`"
                    />
                </div>

                <div v-if="hasParts" class="card shadow-sm mb-4">
                    <div class="card-header">{{ t('item.parts') }}</div>
                    <div class="card-body p-0">
                        <PartsTable :lots="inventory" />
                    </div>
                </div>

                <div v-if="elementCodes.length" class="card shadow-sm">
                    <div class="card-header">{{ t('item.element_codes') }}</div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <tbody>
                                <tr v-for="code in elementCodes" :key="code.code">
                                    <td><ColorDot :rgb="code.color_rgb" :name="code.color_name" /></td>
                                    <td class="text-end"><code>{{ code.code }}</code></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <p v-if="!item.has_inventory" class="text-body-secondary">
                    {{ t('item.no_inventory') }}
                </p>
            </div>
        </div>
    </AppLayout>
</template>
