<script setup>
import { computed } from 'vue';
import { url } from '@/support/base';
import { t } from '@/i18n';

/**
 * Выгрузка в BrickLink XML.
 *
 * Обычная ссылка, а не переход роутера: в ответ приходит файл, и Inertia о таком
 * ответе ничего не знает. Адрес собирается из тех же фильтров, что и список, —
 * выгружается ровно то, что человек видит на экране.
 *
 * Подсказка говорит, каким файл получится. Опись и список желаемого читаются
 * BrickLink по-разному, а по виду файла это не отличить: узнать после загрузки
 * было бы поздно.
 */
const props = defineProps({
    href: { type: String, required: true },
    /** inventory — QTY, «вот что у меня есть»; wanted — MINQTY, «вот чего не хватает». */
    mode: { type: String, default: 'inventory' },
    /** Подпись рядом с иконкой: в строке фильтров не нужна, на странице сборки нужна. */
    label: { type: String, default: null },
});

const hint = computed(() => t(props.mode === 'wanted' ? 'export.as_wanted' : 'export.as_inventory'));
</script>

<template>
    <a
        class="btn btn-outline-secondary text-nowrap"
        :href="url(href)"
        :title="hint"
        :aria-label="label ?? hint"
        download
    >
        <i class="mdi mdi-tray-arrow-down"></i>
        <span v-if="label" class="ms-1">{{ label }}</span>
    </a>
</template>
