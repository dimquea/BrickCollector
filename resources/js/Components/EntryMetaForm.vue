<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import SearchSelect from '@/Components/SearchSelect.vue';
import { patchField } from '@/support/save';
import { notify } from '@/support/toasts';
import { locale, t } from '@/i18n';

/**
 * What the user knows about a copy, as opposed to what the catalog says.
 */
const props = defineProps({
    entryId: { type: Number, required: true },
    // Each section owns its copies, so the form is told where to save rather
    // than assuming one.
    endpoint: { type: String, default: null },
    meta: { type: Object, required: true },
    dictionaries: { type: Object, required: true },
    currency: { type: String, default: 'RUB' },
});

/**
 * Money is stored in minor units and never leaves the server as a fraction.
 * The conversion lives here, at the single point where a person types an
 * amount, so nothing downstream has to think about rounding.
 */
const form = reactive({
    acquired_at: props.meta.acquired_at ?? '',
    price: props.meta.price === null ? '' : (props.meta.price / 100).toFixed(2),
    source_id: props.meta.source_id ?? null,
    storage_id: props.meta.storage_id ?? null,
    note: props.meta.note ?? '',
    status_ids: [...props.meta.status_ids],
    tag_ids: [...props.meta.tag_ids],
});

const emit = defineEmits(['statuses']);

const saving = ref(false);

/** Codes of the ticked statuses, so the card above can redraw its badges. */
function tickedCodes() {
    return props.dictionaries.statuses
        .filter((status) => form.status_ids.includes(status.id) && status.code)
        .map((status) => status.code);
}

/** Kept so a rejected save can put the form back the way the server has it. */
let lastAccepted = snapshot();

function snapshot() {
    return JSON.parse(JSON.stringify(form));
}

async function save() {
    saving.value = true;

    const attempted = snapshot();

    const result = await patchField(
        props.endpoint ?? `/sets/${props.entryId}`,
        {
            acquired_at: form.acquired_at || null,
            // The one place that knows about decimals. Everything below this
            // line deals in integer minor units.
            price: form.price === '' ? null : Math.round(Number(form.price) * 100),
            source_id: form.source_id ? Number(form.source_id) : null,
            storage_id: form.storage_id ? Number(form.storage_id) : null,
            note: form.note || null,
            status_ids: form.status_ids,
            tag_ids: form.tag_ids,
        },
        { onRevert: () => Object.assign(form, lastAccepted) },
    );

    saving.value = false;

    if (result) {
        lastAccepted = attempted;
        emit('statuses', tickedCodes());
        notify(result.message ?? t('collection.saved'), 'success', 2500);
    }
}

// Checkboxes save themselves; a person ticking "box" does not expect to have
// to confirm it. The text fields keep an explicit save so a half-typed note
// is not written on every keystroke.
watch(() => [form.status_ids.length, form.tag_ids.length, form.source_id, form.storage_id], save);

const priceHint = computed(() =>
    form.price === ''
        ? ''
        : new Intl.NumberFormat(locale.value, { style: 'currency', currency: props.currency })
              .format(Number(form.price) || 0),
);

const hasDictionary = (name) => props.dictionaries[name].length > 0;
</script>

<template>
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button
                class="accordion-button collapsed"
                type="button"
                data-bs-toggle="collapse"
                data-bs-target="#entryMeta"
            >
                <i class="mdi mdi-tune me-2"></i>
                {{ t('collection.manage') }}
            </button>
        </h2>

        <div id="entryMeta" class="accordion-collapse collapse">
            <div class="accordion-body">
                <div class="row g-3">
                    <div class="col-12 col-sm-6">
                        <label for="acquiredAt" class="form-label">{{ t('collection.acquired_at') }}</label>
                        <input
                            id="acquiredAt"
                            v-model="form.acquired_at"
                            type="date"
                            class="form-control"
                            @change="save"
                        />
                    </div>

                    <div class="col-12 col-sm-6">
                        <label for="price" class="form-label">{{ t('collection.price') }}</label>
                        <input
                            id="price"
                            v-model="form.price"
                            type="number"
                            step="0.01"
                            min="0"
                            class="form-control"
                            @change="save"
                        />
                        <div v-if="priceHint" class="form-text">{{ priceHint }}</div>
                    </div>

                    <div class="col-12 col-sm-6">
                        <label for="source" class="form-label">{{ t('collection.source') }}</label>
                        <SearchSelect
                            v-if="hasDictionary('sources')"
                            id="source"
                            v-model="form.source_id"
                            :options="dictionaries.sources.map((s) => ({ value: s.id, label: s.name }))"
                            :placeholder="t('catalog.any')"
                        />
                        <p v-else class="form-text mb-0">
                            {{ t('collection.dictionary_empty') }}
                            <Link href="/settings">{{ t('nav.settings') }}</Link>
                        </p>
                    </div>

                    <div class="col-12 col-sm-6">
                        <label for="storage" class="form-label">{{ t('collection.storage') }}</label>
                        <SearchSelect
                            v-if="hasDictionary('storages')"
                            id="storage"
                            v-model="form.storage_id"
                            :options="dictionaries.storages.map((s) => ({ value: s.id, label: s.name }))"
                            :placeholder="t('catalog.any')"
                        />
                        <p v-else class="form-text mb-0">
                            {{ t('collection.dictionary_empty') }}
                            <Link href="/settings">{{ t('nav.settings') }}</Link>
                        </p>
                    </div>

                    <!-- A minifigure has no box and no instructions, so a
                         section may offer no statuses at all. -->
                    <div v-if="dictionaries.statuses.length" class="col-12">
                        <span class="form-label d-block">{{ t('collection.statuses') }}</span>
                        <div class="d-flex flex-wrap gap-3">
                            <div v-for="status in dictionaries.statuses" :key="status.id" class="form-check">
                                <input
                                    :id="`status-${status.id}`"
                                    v-model="form.status_ids"
                                    class="form-check-input"
                                    type="checkbox"
                                    :value="status.id"
                                />
                                <label class="form-check-label" :for="`status-${status.id}`">
                                    {{ status.name }}
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <span class="form-label d-block">{{ t('collection.tags') }}</span>
                        <div v-if="hasDictionary('tags')" class="d-flex flex-wrap gap-3">
                            <div v-for="tag in dictionaries.tags" :key="tag.id" class="form-check">
                                <input
                                    :id="`tag-${tag.id}`"
                                    v-model="form.tag_ids"
                                    class="form-check-input"
                                    type="checkbox"
                                    :value="tag.id"
                                />
                                <label class="form-check-label" :for="`tag-${tag.id}`">
                                    <span class="badge" :class="`text-bg-${tag.color}`">{{ tag.name }}</span>
                                </label>
                            </div>
                        </div>
                        <p v-else class="form-text mb-0">
                            {{ t('collection.dictionary_empty') }}
                            <Link href="/settings">{{ t('nav.settings') }}</Link>
                        </p>
                    </div>

                    <div class="col-12">
                        <label for="note" class="form-label">{{ t('collection.note') }}</label>
                        <textarea
                            id="note"
                            v-model="form.note"
                            class="form-control"
                            rows="3"
                            @change="save"
                        ></textarea>
                    </div>

                    <div class="col-12">
                        <button type="button" class="btn btn-primary" :disabled="saving" @click="save">
                            {{ t('collection.save') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
