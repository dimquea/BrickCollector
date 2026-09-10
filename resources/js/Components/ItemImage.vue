<script setup>
import { computed } from 'vue';

/**
 * Item picture, always served through our own cache route. The source refuses
 * hotlinking, and the route answers with a placeholder when there is nothing
 * to show, so this component never has to deal with a broken image.
 */
const props = defineProps({
    type: { type: String, required: true },
    id: { type: String, required: true },
    colorId: { type: Number, default: 0 },
    alt: { type: String, default: '' },
});

const src = computed(
    () => `/images/${props.type}/${encodeURIComponent(props.id)}/${props.colorId ?? 0}`,
);
</script>

<template>
    <img :src="src" :alt="alt" loading="lazy" decoding="async" class="img-fluid" />
</template>
