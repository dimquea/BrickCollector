<script setup>
import { computed } from 'vue';

/**
 * Статусы и теги экземпляра словами.
 *
 * Идут в подвале карточки следом за значками «коробка» и «инструкция»: те
 * рисуются иконками, потому что стоят почти на каждом наборе и слова заняли бы
 * всю строку. Остальное придумал пользователь — у этого есть только название,
 * нарисовать его иконкой неоткуда.
 */
const props = defineProps({
    meta: { type: Object, required: true },
    dictionaries: { type: Object, required: true },
    /** Коды, уже показанные значками: повторять их словом незачем. */
    drawnAsIcons: { type: Array, default: () => ['box', 'manual'] },
});

const statuses = computed(() =>
    (props.dictionaries.statuses ?? []).filter(
        (status) => props.meta.status_ids.includes(status.id) && ! props.drawnAsIcons.includes(status.code),
    ),
);

const tags = computed(() =>
    (props.dictionaries.tags ?? []).filter((tag) => props.meta.tag_ids.includes(tag.id)),
);
</script>

<template>
    <span
        v-for="status in statuses"
        :key="`status-${status.id}`"
        class="badge text-bg-light border"
    >
        {{ status.name }}
    </span>

    <span
        v-for="tag in tags"
        :key="`tag-${tag.id}`"
        class="badge"
        :class="`text-bg-${tag.color}`"
    >
        {{ tag.name }}
    </span>
</template>
