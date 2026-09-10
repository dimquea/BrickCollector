<script setup>
import { url } from '@/support/base';
import { computed, ref, watch } from 'vue';

/**
 * Item picture.
 *
 * When the server says there is no cached picture, the placeholder is drawn
 * here and no request is made at all. A listing renders 48 cards, and asking
 * the server 48 times only to be told "nothing yet" is a flood that answers
 * nothing — heavy enough, on this platform, to make some of those requests
 * fail outright.
 *
 * Leaving `hasImage` undefined means "ask anyway", for the few places that
 * render a single picture without having looked it up in advance.
 */
const props = defineProps({
    type: { type: String, required: true },
    id: { type: String, required: true },
    colorId: { type: Number, default: 0 },
    alt: { type: String, default: '' },
    hasImage: { type: Boolean, default: undefined },
});

const failed = ref(false);

watch(
    () => [props.type, props.id, props.colorId],
    () => (failed.value = false),
);

const src = computed(
    () => url(`/images/${props.type}/${encodeURIComponent(props.id)}/${props.colorId ?? 0}`),
);

const showImage = computed(() => props.hasImage !== false && !failed.value);
</script>

<template>
    <img
        v-if="showImage"
        :src="src"
        :alt="alt"
        loading="lazy"
        decoding="async"
        class="img-fluid"
        @error="failed = true"
    />

    <svg
        v-else
        class="img-fluid"
        viewBox="0 0 160 120"
        role="img"
        :aria-label="alt"
        preserveAspectRatio="xMidYMid meet"
    >
        <rect width="160" height="120" fill="#e9ecef" />
        <circle cx="80" cy="52" r="22" fill="#dee2e6" />
        <text
            x="80"
            y="60"
            text-anchor="middle"
            font-family="system-ui, sans-serif"
            font-size="22"
            font-weight="600"
            fill="#adb5bd"
        >
            {{ type }}
        </text>
    </svg>
</template>
