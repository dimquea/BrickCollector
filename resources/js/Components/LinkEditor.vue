<script setup>
import { reactive } from 'vue';
import { patchField } from '@/support/save';
import { notify } from '@/support/toasts';
import { t } from '@/i18n';

/**
 * Настройка блоков внешних ссылок.
 *
 * Блоков ровно шесть, они фиксированы: добавить или удалить нельзя, только
 * настроить. Два последних оставлены под собственные ресурсы владельца, у
 * инструкции поле одно — инструкция бывает только у набора.
 *
 * Сохраняем как и везде: без перезагрузки, с откатом значения при отказе.
 */
const props = defineProps({
    rows: { type: Array, required: true },
});

// Своя копия: правка живёт здесь, страница из-за неё не перерисовывается.
const blocks = reactive(props.rows.map((row) => ({ ...row })));

/** Поля паттернов блока: у инструкции один, у остальных три. */
function fields(block) {
    return block.set_only ? ['url_set'] : ['url_set', 'url_minifig', 'url_part'];
}

async function save(block) {
    const before = props.rows.find((row) => row.id === block.id);

    const result = await patchField(
        `/settings/links/${block.id}`,
        {
            enabled: block.enabled,
            label: block.label || null,
            url_set: block.url_set || null,
            url_minifig: block.url_minifig || null,
            url_part: block.url_part || null,
        },
        { onRevert: () => Object.assign(block, before) },
    );

    if (result) {
        notify(result.message ?? t('collection.saved'), 'success', 2000);
    }
}
</script>

<template>
    <div class="alert alert-light border small d-flex gap-2">
        <i class="mdi mdi-information-outline flex-shrink-0"></i>
        <span>{{ t('links.placeholders') }}</span>
    </div>

    <div v-for="block in blocks" :key="block.id" class="border rounded p-3 mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-sm-auto">
                <div class="form-check form-switch mb-0">
                    <input
                        :id="`link-${block.id}-enabled`"
                        v-model="block.enabled"
                        class="form-check-input"
                        type="checkbox"
                        @change="save(block)"
                    />
                    <label class="form-check-label" :for="`link-${block.id}-enabled`">
                        {{ t('links.enabled') }}
                    </label>
                </div>
            </div>

            <div class="col-12 col-sm">
                <label class="form-label small mb-1" :for="`link-${block.id}-label`">
                    {{ t('links.label') }}
                </label>
                <input
                    :id="`link-${block.id}-label`"
                    v-model="block.label"
                    type="text"
                    class="form-control form-control-sm"
                    :placeholder="block.code"
                    @blur="save(block)"
                />
            </div>
        </div>

        <div v-for="field in fields(block)" :key="field" class="mt-2">
            <label class="form-label small mb-1" :for="`link-${block.id}-${field}`">
                {{ t(`links.${field}`) }}
            </label>
            <input
                :id="`link-${block.id}-${field}`"
                v-model="block[field]"
                type="text"
                class="form-control form-control-sm font-monospace"
                spellcheck="false"
                placeholder="https://example.com/{id}"
                @blur="save(block)"
            />
        </div>
    </div>
</template>
