<script setup>
import axios from 'axios';
import { url } from '@/support/base';
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Modal } from 'bootstrap';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import { notify } from '@/support/toasts';
import { t } from '@/i18n';

/**
 * Changing what an assembly is made of.
 *
 * Everything taken out goes back to the loose pile as a lot of its own — a
 * part pulled off a model is a part on the table again, and pretending it
 * returned to the purchase it came from would invent a history.
 */
const props = defineProps({
    entryId: { type: Number, required: true },
    parts: { type: Array, default: () => [] },
});

const emit = defineEmits(['changed']);

const el = ref(null);
let modal = null;

const quantities = reactive({});
const busy = ref(false);

function open() {
    props.parts.forEach((part) => (quantities[part.id] = part.qty));
    modal.show();
}

defineExpose({ open });

async function save(part) {
    const qty = Number(quantities[part.id]);

    if (!Number.isInteger(qty) || qty < 0 || qty === part.qty) {
        return;
    }

    await send(() => axios.patch(url(`/assemblies/${props.entryId}/parts/${part.id}`), { qty }));
}

async function remove(part) {
    await send(() => axios.delete(url(`/assemblies/${props.entryId}/parts/${part.id}`)));
}

async function send(request) {
    busy.value = true;

    try {
        const { data } = await request();

        notify(data.message, 'success', 2000);
        emit('changed');
    } catch (error) {
        notify(
            Object.values(error.response?.data?.errors ?? {}).flat().join(' ')
                || error.response?.data?.message
                || t('errors.save_failed'),
        );
    } finally {
        busy.value = false;
    }
}

onMounted(() => (modal = new Modal(el.value)));
onBeforeUnmount(() => modal?.dispose());
</script>

<template>
    <div ref="el" class="modal fade" tabindex="-1" aria-labelledby="assemblyEditTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="assemblyEditTitle" class="modal-title fs-5">{{ t('assembly.edit_parts') }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" :aria-label="t('parts.cancel')"></button>
                </div>

                <div class="modal-body">
                    <p v-if="!parts.length" class="text-body-secondary mb-0">{{ t('assembly.empty') }}</p>

                    <div v-else class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <tbody>
                                <tr v-for="part in parts" :key="part.id">
                                    <td style="width: 4rem">
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
                                    <td style="width: 9rem">
                                        <div class="input-group input-group-sm">
                                            <input
                                                v-model.number="quantities[part.id]"
                                                type="number"
                                                min="0"
                                                class="form-control"
                                                :aria-label="t('parts.quantity')"
                                                :disabled="busy"
                                                @change="save(part)"
                                            />
                                            <button
                                                type="button"
                                                class="btn btn-outline-danger"
                                                :title="t('assembly.give_back')"
                                                :disabled="busy"
                                                @click="remove(part)"
                                            >
                                                <i class="mdi mdi-minus"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer">
                    <span class="text-body-secondary small me-auto">{{ t('assembly.give_back_hint') }}</span>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        {{ t('parts.cancel') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
