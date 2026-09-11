<script setup>
import { reactive } from 'vue';
import { patchField } from '@/support/save';
import { notify } from '@/support/toasts';
import { t } from '@/i18n';

/**
 * Сколько строк в списке и какого размера карточки.
 *
 * Размер выбирается отдельно для телефона и широкого экрана: решает не
 * JavaScript, а CSS — обе настройки уезжают классами, а какая сработает,
 * определяет ширина окна.
 *
 * У деталей список — таблица, размер карточки к ней не применим, поэтому у
 * такого списка остаётся только количество.
 */
const props = defineProps({
    lists: { type: Array, required: true },
});

const rows = reactive(props.lists.map((list) => ({
    ...list,
    card_size: { ...list.card_size },
})));

async function save(row) {
    const before = props.lists.find((list) => list.key === row.key);

    const result = await patchField(
        '/settings',
        {
            per_page: { [row.key]: Number(row.per_page) },
            card_size: { [row.key]: row.card_size },
        },
        {
            onRevert: () => Object.assign(row, {
                ...before,
                card_size: { ...before.card_size },
            }),
        },
    );

    if (result) {
        notify(result.message ?? t('settings.saved'), 'success', 2000);
    }
}
</script>

<template>
    <p class="text-body-secondary small">{{ t('settings.lists_hint') }}</p>

    <div v-for="row in rows" :key="row.key" class="border rounded p-3 mb-3">
        <h4 class="h6 mb-3">{{ t(`settings.list_${row.key}`) }}</h4>

        <div class="row g-3 align-items-end">
            <div class="col-12 col-sm-4 col-lg-3">
                <label class="form-label small mb-1" :for="`per-page-${row.key}`">
                    {{ t('settings.per_page') }}
                </label>
                <input
                    :id="`per-page-${row.key}`"
                    v-model="row.per_page"
                    type="number"
                    min="6"
                    max="200"
                    class="form-control form-control-sm"
                    @change="save(row)"
                />
            </div>

            <template v-if="row.cards">
                <div
                    v-for="screen in ['desktop', 'mobile']"
                    :key="screen"
                    class="col-6 col-sm-4 col-lg-3"
                >
                    <label class="form-label small mb-1" :for="`card-${row.key}-${screen}`">
                        {{ t('settings.card_size') }} · {{ t(`settings.screen_${screen}`) }}
                    </label>
                    <select
                        :id="`card-${row.key}-${screen}`"
                        v-model="row.card_size[screen]"
                        class="form-select form-select-sm"
                        @change="save(row)"
                    >
                        <option value="large">{{ t('settings.card_large') }}</option>
                        <option value="small">{{ t('settings.card_small') }}</option>
                    </select>
                </div>
            </template>

            <div v-else class="col-12 col-sm">
                <p class="text-body-secondary small mb-0">{{ t('settings.list_table_hint') }}</p>
            </div>
        </div>
    </div>
</template>
