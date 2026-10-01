<script setup>
import { computed } from 'vue';
import { url } from '@/support/base';
import { zoom as openZoom } from '@/support/lightbox';
import { t } from '@/i18n';

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
    /** Открывать ли снимок во весь экран по щелчку; см. ItemImage. */
    zoom: { type: Boolean, default: false },
});

const src = computed(() => (props.hasImage ? url(`/images/assembly/${props.id}`) : null));

// Заглушку увеличивать незачем; размером снимка не проверяемся, см. ItemImage.
const zoomable = computed(() => props.zoom && src.value !== null);

function open() {
    if (zoomable.value) {
        openZoom({ kind: 'assembly', id: props.id, hasImage: true, title: props.alt });
    }
}
</script>

<template>
    <img
        v-if="src"
        :src="src"
        :alt="alt"
        loading="lazy"
        decoding="async"
        class="img-fluid"
        :class="{ zoomable }"
        :role="zoomable ? 'button' : null"
        :tabindex="zoomable ? 0 : null"
        :title="zoomable ? t('item.zoom') : null"
        @click="open"
        @keydown.enter.prevent="open"
        @keydown.space.prevent="open"
    />

    <svg v-else class="img-fluid" viewBox="0 0 160 120" role="img" :aria-label="alt" preserveAspectRatio="xMidYMid meet">
        <!-- Цвета — переменные Bootstrap: на тёмной теме заглушка должна быть
             тёмной, иначе она светит на весь список. -->
        <rect width="160" height="120" fill="var(--bs-secondary-bg)" />
        <rect x="52" y="40" width="26" height="18" rx="3" fill="var(--bs-tertiary-bg)" />
        <rect x="82" y="40" width="26" height="18" rx="3" fill="var(--bs-border-color)" />
        <rect x="67" y="62" width="26" height="18" rx="3" fill="var(--bs-tertiary-bg)" />
    </svg>
</template>
