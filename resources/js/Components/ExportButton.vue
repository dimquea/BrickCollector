<script setup>
import { url } from '@/support/base';
import { t } from '@/i18n';

/**
 * Выгрузка списка: кнопка с меню видов.
 *
 * Обычные ссылки, а не переходы роутера: в ответ приходит файл, и Inertia о
 * таком ответе ничего не знает. Адрес собирается из тех же фильтров, что и
 * список, — выгружается ровно то, что человек видит на экране.
 *
 * Прежде вид угадывался по фильтру: включён «потеряны» — значит нужен список
 * желаемого. Угадывание было верным чаще, чем нет, но выбора не оставляло, а
 * по виду файла не понять, чем он окажется на той стороне.
 */
defineProps({
    /** @type {{key: string, label: string, href: string}[]} */
    items: { type: Array, required: true },
    /** Подпись рядом с иконкой: в строке фильтров не нужна, на странице сборки нужна. */
    label: { type: String, default: null },
});
</script>

<template>
    <div class="btn-group">
        <button
            type="button"
            class="btn btn-outline-secondary text-nowrap dropdown-toggle"
            data-bs-toggle="dropdown"
            :title="t('export.title')"
            :aria-label="label ?? t('export.title')"
        >
            <i class="mdi mdi-tray-arrow-down"></i>
            <span v-if="label" class="ms-1">{{ label }}</span>
        </button>

        <ul class="dropdown-menu dropdown-menu-end">
            <li v-for="item in items" :key="item.key">
                <a class="dropdown-item" :href="url(item.href)" download>
                    {{ t(item.label) }}
                    <span v-if="item.hint" class="d-block small text-body-secondary text-wrap" style="max-width: 18rem">
                        {{ t(item.hint) }}
                    </span>
                </a>
            </li>
        </ul>
    </div>
</template>
