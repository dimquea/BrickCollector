<script setup>
import { reactive, ref } from 'vue';
import EntryMetaFields from '@/Components/EntryMetaFields.vue';
import { patchField } from '@/support/save';
import { notify } from '@/support/toasts';
import { t } from '@/i18n';

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

const emit = defineEmits(['saved']);

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

        // Карточка над формой показывает те же значения; отдаём их ей целиком,
        // уже приведёнными к тому виду, в каком их отдал бы сервер.
        emit('saved', {
            codes: tickedCodes(),
            meta: {
                acquired_at: form.acquired_at || null,
                price: form.price === '' ? null : Math.round(Number(form.price) * 100),
                source_id: form.source_id ? Number(form.source_id) : null,
                storage_id: form.storage_id ? Number(form.storage_id) : null,
                note: form.note || null,
                status_ids: [...form.status_ids],
                tag_ids: [...form.tag_ids],
            },
        });
        notify(result.message ?? t('collection.saved'), 'success', 2500);
    }
}

// Поля сообщают о каждой правке, а сохраняет их эта обёртка. Галочка пишется
// сразу — поставив «коробку», её не ждут подтверждать; текстовое поле сообщает
// по окончании ввода, а не на каждой букве, иначе недописанная заметка
// оказалась бы в базе.
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
                <EntryMetaFields
                    :form="form"
                    :dictionaries="dictionaries"
                    :currency="currency"
                    :prefix="`entry-${entryId}`"
                    @changed="save"
                />

                <button type="button" class="btn btn-primary mt-3" :disabled="saving" @click="save">
                    {{ t('collection.save') }}
                </button>
            </div>
        </div>
    </div>
</template>
