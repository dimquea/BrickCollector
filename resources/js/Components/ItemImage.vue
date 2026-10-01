<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { url } from '@/support/base';
import { zoom as openZoom } from '@/support/lightbox';
import { t } from '@/i18n';

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
    /**
     * Открывать ли снимок во весь экран по щелчку.
     *
     * Спрашивается у места вызова, а не решается здесь: в списках по картинке
     * переходят к предмету, и забрать у них щелчок значило бы забрать привычный
     * способ открыть карточку. Внутри карточки переходить уже некуда — там
     * щелчок и достаётся увеличению.
     */
    zoom: { type: Boolean, default: false },
    /** Подпись в оверлее: артикул, цвет — то, чем картинка названа в списке. */
    zoomSubtitle: { type: String, default: null },
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

/*
 * Увеличивать есть во что?
 *
 * В кэше лежит то, что отдал BrickLink: у ходовой детали это 200 точек по
 * ширине, у набора — 640. В строке таблицы снимок нарисован в 56 и крупнее
 * станет заведомо, а в карточке он уже шире, чем исходник, и «увеличение»
 * показало бы картинку меньше той, на которую нажали.
 *
 * Поэтому решает не место вызова, а сам файл: ширину исходника видно только
 * после загрузки, и до неё снимок обычный, без курсора и без обещания.
 */
const natural = ref(0);
const rendered = ref(0);

function measure(event) {
    natural.value = event.target.naturalWidth;
    // Ноль — картинка ещё не на экране (свёрнутый блок, список ниже сгиба):
    // сравнивать не с чем, и отказывать не за что.
    rendered.value = event.target.clientWidth;
}

// Заглушку увеличивать незачем: там нечего разглядывать.
const zoomable = computed(
    () => props.zoom && src.value !== null && natural.value > rendered.value + 8,
);

function open() {
    if (zoomable.value) {
        openZoom({
            kind: 'item',
            type: props.type,
            id: props.id,
            colorId: props.colorId ?? 0,
            title: props.alt,
            subtitle: props.zoomSubtitle,
        });
    }
}
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
        :class="{ zoomable }"
        :role="zoomable ? 'button' : null"
        :tabindex="zoomable ? 0 : null"
        :title="zoomable ? t('item.zoom') : null"
        @error="attempt += 1"
        @load="measure"
        @click="open"
        @keydown.enter.prevent="open"
        @keydown.space.prevent="open"
    />

    <svg
        v-else
        class="img-fluid"
        viewBox="0 0 160 120"
        role="img"
        :aria-label="alt"
        preserveAspectRatio="xMidYMid meet"
    >
        <!-- Цвета — переменные Bootstrap: на тёмной теме заглушка должна быть
             тёмной, иначе она светит на весь список. -->
        <rect width="160" height="120" fill="var(--bs-secondary-bg)" />
        <circle cx="80" cy="52" r="22" fill="var(--bs-tertiary-bg)" />
        <text
            x="80"
            y="60"
            text-anchor="middle"
            font-family="system-ui, sans-serif"
            font-size="22"
            font-weight="600"
            fill="var(--bs-secondary-color)"
        >
            {{ type }}
        </text>
    </svg>
</template>
