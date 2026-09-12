<script setup>
import { computed } from 'vue';
import { url } from '@/support/base';

/**
 * The picture of an assembly, or a stand-in when it has none.
 *
 * Unlike an item, an assembly has nothing in the catalogue to fetch a picture
 * from: either its owner uploaded one or there is none, and asking the server
 * to find out is pointless — the page already knows.
 */
const props = defineProps({
    id: { type: Number, required: true },
    hasImage: { type: Boolean, default: false },
    alt: { type: String, default: '' },
});

const src = computed(() => (props.hasImage ? url(`/images/assembly/${props.id}`) : null));
</script>

<template>
    <img v-if="src" :src="src" :alt="alt" loading="lazy" decoding="async" class="img-fluid" />

    <svg v-else class="img-fluid" viewBox="0 0 160 120" role="img" :aria-label="alt" preserveAspectRatio="xMidYMid meet">
        <rect width="160" height="120" fill="#e9ecef" />
        <rect x="52" y="40" width="26" height="18" rx="3" fill="#dee2e6" />
        <rect x="82" y="40" width="26" height="18" rx="3" fill="#ced4da" />
        <rect x="67" y="62" width="26" height="18" rx="3" fill="#dee2e6" />
    </svg>
</template>
