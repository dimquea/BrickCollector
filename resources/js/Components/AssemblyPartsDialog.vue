<script setup>
import axios from 'axios';
import { url } from '@/support/base';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Modal } from 'bootstrap';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import { debounce } from '@/support/debounce';
import { notify } from '@/support/toasts';
import { t } from '@/i18n';

/**
 * Taking parts out of the loose pile and into an assembly.
 *
 * The list is fetched rather than shipped with the page: the loose pile can be
 * hundreds of part-and-colour pairs, and the page needs none of them until the
 * dialog is opened. Each row is added on its own, so several parts can be put
 * in without closing anything.
 */
const props = defineProps({
    entryId: { type: Number, required: true },
});

const emit = defineEmits(['changed']);

const el = ref(null);
let modal = null;

const filters = reactive({ q: '', color_id: null });
const parts = ref([]);
const colours = ref([]);
const loading = ref(false);
const taking = reactive({});

const colourOptions = computed(() => colours.value.map((colour) => ({ value: colour.id, label: colour.name })));

async function load() {
    loading.value = true;

    try {
        const { data } = await axios.get(url(`/assemblies/${props.entryId}/loose`), {
            params: { q: filters.q || null, color_id: filters.color_id || null },
        });

        parts.value = data.parts;
        colours.value = data.colours;
    } finally {
        loading.value = false;
    }
}

const key = (part) => `${part.item_id}/${part.color_id}`;

async function take(part) {
    const qty = Number(taking[key(part)] ?? 1);

    if (qty < 1) {
        return;
    }

    try {
        const { data } = await axios.post(url(`/assemblies/${props.entryId}/parts`), {
            item_id: part.item_id,
            color_id: part.color_id,
            qty,
        });

        notify(data.message, 'success', 2000);
        delete taking[key(part)];

        await load();
        emit('changed');
    } catch (error) {
        notify(
            Object.values(error.response?.data?.errors ?? {}).flat().join(' ')
                || error.response?.data?.message
                || t('errors.save_failed'),
        );
    }
}

function open() {
    filters.q = '';
    filters.color_id = null;
    modal.show();
    load();
}

defineExpose({ open });

watch(() => filters.q, debounce(load, 300));
watch(() => filters.color_id, load);

onMounted(() => (modal = new Modal(el.value)));
onBeforeUnmount(() => modal?.dispose());
</script>

<template>
    <div ref="el" class="modal fade" tabindex="-1" aria-labelledby="assemblyAddTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="assemblyAddTitle" class="modal-title fs-5">{{ t('assembly.add_parts') }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" :aria-label="t('parts.cancel')"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-7">
                            <label for="looseQuery" class="form-label">{{ t('parts.query_hint') }}</label>
                            <input id="looseQuery" v-model="filters.q" type="search" class="form-control" />
                        </div>
                        <div class="col-12 col-sm-5">
                            <label for="looseColour" class="form-label">{{ t('lot.color') }}</label>
                            <SearchSelect
                                id="looseColour"
                                v-model="filters.color_id"
                                :options="colourOptions"
                                :placeholder="t('catalog.any')"
                            />
                        </div>
                    </div>

                    <p v-if="!parts.length && !loading" class="text-body-secondary mb-0">
                        {{ t('assembly.loose_empty') }}
                    </p>

                    <div v-else class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 4rem"></th>
                                    <th>{{ t('lot.item') }}</th>
                                    <th class="text-end text-nowrap">{{ t('assembly.available') }}</th>
                                    <th class="text-end text-nowrap" style="width: 9rem">{{ t('assembly.take') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="part in parts" :key="key(part)">
                                    <td>
                                        <ItemImage
                                            type="P"
                                            :id="part.item_id"
                                            :color-id="part.color_id"
                                            :alt="part.name"
                                        />
                                    </td>
                                    <td>
                                        <span class="line-clamp-2" :title="part.name">{{ part.name }}</span>
                                        <div class="d-flex align-items-center gap-2 mt-1">
                                            <span class="badge text-bg-light border">{{ part.item_id }}</span>
                                            <ColorDot :rgb="part.color_rgb" :name="part.color_name" />
                                        </div>
                                    </td>
                                    <td class="text-end fw-semibold">{{ part.available }}</td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input
                                                v-model.number="taking[key(part)]"
                                                type="number"
                                                min="1"
                                                :max="part.available"
                                                class="form-control"
                                                :placeholder="String(1)"
                                                :aria-label="t('assembly.take')"
                                            />
                                            <button
                                                type="button"
                                                class="btn btn-outline-primary"
                                                :title="t('assembly.add_parts')"
                                                @click="take(part)"
                                            >
                                                <i class="mdi mdi-plus"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        {{ t('parts.cancel') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
