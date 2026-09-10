<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { url } from '@/support/base';

/**
 * Item picture.
 *
 * Two places it can come from, tried in that order.
 *
 * From our cache — that is what lets a collection keep its pictures when the
 * source will not answer. Not cached — the browser fetches it from the source
 * itself, so nothing waits on the download queue: the picture is there the
 * moment the card is. Neither answers — the placeholder is drawn here.
 *
 * The card goes to the source directly rather than letting our route redirect
 * it there: a listing renders 48 of these, and 48 redirects would be the
 * request flood we removed once already.
 */
const props = defineProps({
    type: { type: String, required: true },
    id: { type: String, required: true },
    colorId: { type: Number, default: 0 },
    alt: { type: String, default: '' },
    /** false — картинки в кэше нет; undefined — не спрашивали, начнём с кэша. */
    hasImage: { type: Boolean, default: undefined },
});

const page = usePage();

const cached = computed(
    () => url(`/images/${props.type}/${encodeURIComponent(props.id)}/${props.colorId ?? 0}`),
);

const source = computed(() => {
    const pattern = page.props.imageUrl;

    if (typeof pattern !== 'string' || pattern === '') {
        return null;
    }

    return pattern
        .replaceAll('{type}', props.type)
        .replaceAll('{color}', String(props.colorId ?? 0))
        .replaceAll('{id}', encodeURIComponent(props.id));
});

// Известно, что в кэше пусто — значит и спрашивать нас не о чем: сразу к
// источнику. В остальных случаях начинаем со своего, а источник остаётся
// запасным вариантом на случай, если файл из кэша пропал.
const candidates = computed(() =>
    (props.hasImage === false ? [source.value] : [cached.value, source.value])
        .filter((candidate) => candidate !== null),
);

const attempt = ref(0);

watch(
    () => [props.type, props.id, props.colorId, props.hasImage],
    () => (attempt.value = 0),
);

const src = computed(() => candidates.value[attempt.value] ?? null);
</script>

<template>
    <img
        v-if="src"
        :key="src"
        :src="src"
        :alt="alt"
        loading="lazy"
        decoding="async"
        class="img-fluid"
        @error="attempt += 1"
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
