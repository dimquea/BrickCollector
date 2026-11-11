<script setup>
import { computed } from 'vue';
import { locale, t } from '@/i18n';

/**
 * Что коллекция переименовала по журналу BrickLink в последний раз.
 *
 * Переименование идёт само, после каждого обновления справочника, и потому
 * должно быть видно: артикул на этикетке коробки и в приложении могут
 * разойтись, и «куда делась 3957» без ответа — загадка. Список свёрнут: он
 * бывает длинным, а нужен, только когда что-то ищут.
 */
const props = defineProps({
    report: { type: Object, default: null },
});

const date = computed(() =>
    props.report
        ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' })
              .format(new Date(props.report.at.replace(' ', 'T')))
        : '',
);

const marks = computed(() => props.report?.marks ?? 0);
const hidden = computed(() => (props.report ? props.report.total - props.report.items.length : 0));

const type = (code) => t(`settings.item_types.${code}`);

// Где предмет встречался — только то, что не ноль: «в желаемом: 0» у каждой
// строки — шум.
const where = (item) =>
    ['entries', 'rows', 'wishes']
        .filter((field) => item[field] > 0)
        .map((field) => t(`settings.renames_${field}`, { count: item[field] }))
        .join(', ');
</script>

<template>
    <div v-if="report" class="mt-3">
        <p class="mb-1">
            <i class="mdi mdi-swap-horizontal me-1"></i>
            {{ t('settings.renames', { date, count: report.total }) }}
            <span v-if="marks" class="text-body-secondary">· {{ t('settings.renames_marks', { count: marks }) }}</span>
        </p>

        <p class="text-body-secondary small mb-1">{{ t('settings.renames_hint') }}</p>

        <details>
            <summary class="small">{{ t('settings.renames_show') }}</summary>

            <ul class="list-unstyled small mt-2 mb-0">
                <li
                    v-for="item in report.items"
                    :key="`${item.from_type}/${item.from_id}`"
                    class="mb-2"
                >
                    <span class="badge text-bg-light border">{{ item.from_id }}</span>
                    <i class="mdi mdi-arrow-right mx-1"></i>
                    <span class="badge text-bg-light border">{{ item.to_id }}</span>
                    {{ item.name }}
                    <i v-if="item.marks" class="mdi mdi-bookmark-outline text-warning ms-1" :title="t('settings.renames_marks', { count: item.marks })"></i>

                    <div class="text-body-secondary">
                        {{ where(item) }}
                    </div>

                    <!-- Смену типа коллекция сама не пишет: это не только
                         номер, но и устройство строк. -->
                    <div v-if="!item.applied" class="text-warning-emphasis">
                        <i class="mdi mdi-alert-outline me-1"></i>
                        {{ t('settings.renames_left', { from: type(item.from_type), to: type(item.to_type) }) }}
                    </div>
                </li>
            </ul>

            <p v-if="hidden > 0" class="small text-body-secondary mb-0">{{ t('settings.renames_more', { count: hidden }) }}</p>
        </details>
    </div>
</template>
