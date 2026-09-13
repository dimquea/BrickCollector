<script setup>
import SearchSelect from '@/Components/SearchSelect.vue';
import { t } from '@/i18n';

/**
 * Порядок списка: поле и направление.
 *
 * Живёт рядом с фильтрами, потому что отвечает на тот же вопрос — «что мне
 * показать» — и уезжает в адрес теми же руками. Пустое поле означает обычный
 * порядок раздела, у каждого он свой, поэтому в плейсхолдере стоит не «без
 * сортировки», а «по умолчанию».
 */
defineProps({
    by: { type: String, default: null },
    dir: { type: String, default: 'asc' },
    /** [{ value, label }] — что этот раздел умеет упорядочивать. */
    options: { type: Array, required: true },
    id: { type: String, default: 'sort' },
});

const emit = defineEmits(['update:by', 'update:dir']);
</script>

<template>
    <div class="d-flex align-items-end gap-2">
        <div class="flex-grow-1">
            <label :for="id" class="form-label">{{ t('sort.title') }}</label>
            <SearchSelect
                :id="id"
                :model-value="by"
                :options="options"
                :placeholder="t('sort.default')"
                @update:model-value="emit('update:by', $event)"
            />
        </div>

        <button
            type="button"
            class="btn btn-outline-secondary"
            :title="dir === 'desc' ? t('sort.desc') : t('sort.asc')"
            :aria-label="dir === 'desc' ? t('sort.desc') : t('sort.asc')"
            @click="emit('update:dir', dir === 'desc' ? 'asc' : 'desc')"
        >
            <i class="mdi" :class="dir === 'desc' ? 'mdi-sort-descending' : 'mdi-sort-ascending'"></i>
        </button>
    </div>
</template>
