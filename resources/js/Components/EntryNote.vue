<script setup>
import { t } from '@/i18n';

/**
 * Заметка об экземпляре.
 *
 * Стоит между карточкой и «Управлением», а не внутри: её пишут, чтобы потом
 * читать, а не чтобы открывать ради этого аккордеон. Переносы строк сохраняем —
 * их ставили осмысленно.
 */
defineProps({
    note: { type: String, default: null },
});
</script>

<template>
    <div v-if="note" class="card shadow-sm mt-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="mdi mdi-note-text-outline"></i>
            {{ t('collection.note') }}
        </div>
        <div class="card-body" style="white-space: pre-line">{{ note }}</div>
    </div>
</template>
