<script setup>
import { t } from '@/i18n';

/**
 * Кнопки на чужие каталоги.
 *
 * Стоят под карточкой: это не про сам предмет, а про то, где о нём почитать
 * ещё. Пустой список блока не рисует — пустая рамка с заголовком сообщает
 * только о том, что чего-то не хватает.
 *
 * Адреса чужие, поэтому уходим в новую вкладку и не отдаём referrer: соседний
 * сайт не должен узнавать адрес чьей-то домашней панели.
 */
defineProps({
    links: { type: Array, default: () => [] },
});
</script>

<template>
    <div v-if="links.length" class="card shadow-sm mt-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="mdi mdi-open-in-new"></i>
            {{ t('links.title') }}
        </div>
        <div class="card-body d-flex flex-wrap gap-2">
            <a
                v-for="link in links"
                :key="link.url"
                :href="link.url"
                class="btn btn-sm btn-outline-secondary"
                target="_blank"
                rel="noopener noreferrer"
            >
                {{ link.label }}
                <i class="mdi mdi-open-in-new ms-1 small"></i>
            </a>
        </div>
    </div>
</template>
