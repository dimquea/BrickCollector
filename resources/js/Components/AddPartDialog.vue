<script setup>
import { url } from '@/support/base';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Modal } from 'bootstrap';
import SearchSelect from '@/Components/SearchSelect.vue';
import { locale, t, tChoice } from '@/i18n';

/**
 * Adding a part: how many, in which colour, and where to.
 *
 * "Where to" is a new lot, a lot already held in that colour, or an assembly
 * being built. A new lot is another purchase; topping one up is more of the
 * same; an assembly is a part bought for the model rather than for the drawer.
 * Only the person knows which, so the dialog asks.
 *
 * The request goes out once the dialog has finished closing. Sent straight
 * away, the page changes under a modal that is still open, and Bootstrap's
 * backdrop outlives it, greying out the page that comes next.
 */
const props = defineProps({
    item: { type: Object, required: true },
    colours: { type: Array, default: () => [] },
    lots: { type: Array, default: () => [] },
    assemblies: { type: Array, default: () => [] },
});

const el = ref(null);
let modal = null;
let pending = false;

// "new", "lot:12" or "assembly:3" — one list of radio buttons, so the choice
// reads as one question with several answers.
const form = reactive({ qty: 1, color_id: null, target: 'new' });
const errors = ref({});
const sending = ref(false);

const colourOptions = computed(() => props.colours.map((colour) => ({ value: colour.id, label: colour.name })));

// Only a lot of the chosen colour can take more; the server refuses any other.
// An assembly takes any colour, being a list of parts rather than a pile of
// one of them.
const lotsInColour = computed(() =>
    props.lots.filter((lot) => String(lot.color_id) === String(form.color_id)),
);

// A lot picked under one colour means nothing under another.
watch(() => form.color_id, () => (form.target = 'new'));

const ready = computed(() => form.color_id !== null && form.color_id !== '' && Number(form.qty) >= 1);

const lotLabel = (lot) => [
    lot.date ? new Intl.DateTimeFormat(locale.value).format(new Date(lot.date)) : t('parts.lot_undated'),
    tChoice('parts.pieces', lot.qty),
].join(' · ');

/** Opens the dialog, with a colour already chosen when one was clicked. */
function open(colorId = null) {
    form.qty = 1;
    form.color_id = colorId ?? props.item.image_color_id ?? null;
    form.target = 'new';
    errors.value = {};
    modal.show();
}

defineExpose({ open });

function submit() {
    if (!ready.value) {
        return;
    }

    pending = true;
    modal.hide();
}

function send() {
    pending = false;
    sending.value = true;

    const [kind, id] = form.target.split(':');

    router.post(
        url(`/catalog/P/${encodeURIComponent(props.item.id)}/add`),
        {
            qty: Number(form.qty),
            color_id: Number(form.color_id),
            lot_id: kind === 'lot' ? Number(id) : null,
            assembly_id: kind === 'assembly' ? Number(id) : null,
        },
        {
            // A refusal comes back to this page. Keep what was typed and
            // show the reason in the dialog it was typed into.
            preserveState: true,
            preserveScroll: true,
            onError: (reasons) => {
                errors.value = reasons;
                modal.show();
            },
            onFinish: () => (sending.value = false),
        },
    );
}

function onHidden() {
    if (pending) {
        send();
    }
}

onMounted(() => {
    modal = new Modal(el.value);
    el.value.addEventListener('hidden.bs.modal', onHidden);
});

onBeforeUnmount(() => {
    el.value?.removeEventListener('hidden.bs.modal', onHidden);
    modal?.dispose();
});
</script>

<template>
    <div ref="el" class="modal fade" tabindex="-1" aria-labelledby="addPartTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" @submit.prevent="submit">
                <div class="modal-header">
                    <h2 id="addPartTitle" class="modal-title fs-5">{{ t('collection.add') }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" :aria-label="t('parts.cancel')"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-sm-4">
                            <label for="addQty" class="form-label">{{ t('parts.quantity') }}</label>
                            <input
                                id="addQty"
                                v-model.number="form.qty"
                                type="number"
                                min="1"
                                max="999"
                                class="form-control"
                                :class="{ 'is-invalid': errors.qty }"
                                required
                            />
                            <div v-if="errors.qty" class="invalid-feedback">{{ errors.qty }}</div>
                        </div>

                        <div class="col-12 col-sm-8">
                            <label for="addColour" class="form-label">{{ t('lot.color') }}</label>
                            <SearchSelect id="addColour" v-model="form.color_id" :options="colourOptions" />
                            <div v-if="errors.color_id" class="invalid-feedback d-block">{{ errors.color_id }}</div>
                        </div>

                        <fieldset class="col-12">
                            <legend class="form-label fs-6">{{ t('parts.lot_target') }}</legend>

                            <div class="form-check">
                                <input
                                    id="addLotNew"
                                    v-model="form.target"
                                    class="form-check-input"
                                    type="radio"
                                    value="new"
                                />
                                <label class="form-check-label" for="addLotNew">{{ t('parts.lot_new') }}</label>
                            </div>

                            <div v-for="lot in lotsInColour" :key="lot.entry_id" class="form-check">
                                <input
                                    :id="`addLot${lot.entry_id}`"
                                    v-model="form.target"
                                    class="form-check-input"
                                    type="radio"
                                    :value="`lot:${lot.entry_id}`"
                                />
                                <label class="form-check-label" :for="`addLot${lot.entry_id}`">
                                    {{ lotLabel(lot) }}
                                </label>
                            </div>

                            <template v-if="assemblies.length">
                                <hr class="my-2" />
                                <div v-for="assembly in assemblies" :key="assembly.id" class="form-check">
                                    <input
                                        :id="`addAssembly${assembly.id}`"
                                        v-model="form.target"
                                        class="form-check-input"
                                        type="radio"
                                        :value="`assembly:${assembly.id}`"
                                    />
                                    <label class="form-check-label" :for="`addAssembly${assembly.id}`">
                                        <i class="mdi mdi-shape-outline me-1"></i>{{ assembly.name }}
                                    </label>
                                </div>
                            </template>

                            <div v-if="errors.lot_id || errors.assembly_id" class="invalid-feedback d-block">
                                {{ errors.lot_id ?? errors.assembly_id }}
                            </div>
                        </fieldset>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        {{ t('parts.cancel') }}
                    </button>
                    <button type="submit" class="btn btn-primary" :disabled="!ready || sending">
                        <i class="mdi mdi-plus"></i>
                        {{ t('collection.add') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
