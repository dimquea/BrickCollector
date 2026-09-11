<script setup>
import { computed } from 'vue';
import { locale, t } from '@/i18n';

/**
 * Где лежит и как достался — строками в карточке.
 *
 * Ровно то же самое правится в «Управлении», но там оно спрятано под
 * аккордеоном, а знать это хочется сразу. Пустые значения строк не создают:
 * «Источник: —» не сообщает ничего.
 */
const props = defineProps({
    meta: { type: Object, required: true },
    dictionaries: { type: Object, required: true },
    currency: { type: String, default: 'RUB' },
});

const named = (list, id) => (list ?? []).find((row) => row.id === id)?.name ?? null;

const storage = computed(() => named(props.dictionaries.storages, props.meta.storage_id));

/** Источник, дата и цена — одна строка: это всё про одну покупку. */
const purchase = computed(() => {
    const parts = [];
    const source = named(props.dictionaries.sources, props.meta.source_id);

    if (source) {
        parts.push(source);
    }

    if (props.meta.acquired_at) {
        parts.push(new Intl.DateTimeFormat(locale.value).format(new Date(props.meta.acquired_at)));
    }

    if (props.meta.price !== null && props.meta.price !== undefined && props.meta.price !== '') {
        parts.push(
            new Intl.NumberFormat(locale.value, { style: 'currency', currency: props.currency })
                .format(props.meta.price / 100),
        );
    }

    return parts;
});
</script>

<template>
    <li v-if="storage" class="list-group-item d-flex justify-content-between gap-2">
        <span class="text-body-secondary">{{ t('collection.storage') }}</span>
        <span class="text-end">{{ storage }}</span>
    </li>

    <li v-if="purchase.length" class="list-group-item d-flex flex-wrap gap-2 align-items-baseline">
        <span v-for="(part, index) in purchase" :key="index" :class="index === 0 ? '' : 'text-body-secondary'">
            {{ part }}
        </span>
    </li>
</template>
