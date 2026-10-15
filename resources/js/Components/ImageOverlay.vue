<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import ItemImage from '@/Components/ItemImage.vue';
import AssemblyImage from '@/Components/AssemblyImage.vue';
import { closeZoom, shown } from '@/support/lightbox';
import { t } from '@/i18n';

/**
 * Снимок во весь экран — один на всё приложение, в разметке слоя.
 *
 * Не модал бутстрапа: от окна здесь нужна одна затенённая подложка, а рамка,
 * поля и заголовок отнимали бы у картинки то самое место, ради которого её и
 * открывают.
 *
 * Открывается она в свою величину, не больше экрана. Дальше — как привыкли:
 * колесом на настольной машине, щипком на телефоне, и перетаскиванием, когда
 * увеличенное уже не помещается. Двойной щелчок с его единственным «вдвое»
 * этого не заменял: разглядывают печать на детали, а не ступеньку масштаба.
 *
 * Рисуют картинку те же ItemImage и AssemblyImage, что и в списках: правила
 * «кэш, потом источник, потом заглушка» остаются в одном месте.
 */

/** Дальше восьмикратного смотреть не на что: в файле столько точек нет. */
const MAX_SCALE = 8;

const frame = ref(null);
const picture = ref(null);
const scale = ref(1);
const offset = reactive({ x: 0, y: 0 });

/** Пальцы на экране: один тащит, два щиплют. */
const pointers = new Map();
let pinch = null;

const style = computed(() => ({
    transform: `translate(${offset.x}px, ${offset.y}px) scale(${scale.value})`,
}));

function image() {
    return picture.value?.$el ?? null;
}

function reset() {
    scale.value = 1;
    offset.x = 0;
    offset.y = 0;
    pointers.clear();
    pinch = null;
}

/**
 * Не даёт утащить картинку за край.
 *
 * Пока увеличенное помещается в окно, смещения нет вовсе — картинка стоит
 * посередине. Как только перестало помещаться, край можно довести до края
 * окна и не дальше: иначе снимок уезжает в пустоту, и непонятно, куда он делся.
 */
function clamp() {
    const img = image();

    if (! img || ! frame.value) {
        return;
    }

    const limitX = Math.max(0, (img.clientWidth * scale.value - frame.value.clientWidth) / 2);
    const limitY = Math.max(0, (img.clientHeight * scale.value - frame.value.clientHeight) / 2);

    offset.x = Math.min(limitX, Math.max(-limitX, offset.x));
    offset.y = Math.min(limitY, Math.max(-limitY, offset.y));
}

/**
 * Увеличивает вокруг точки: под курсором (или между пальцами) остаётся то же
 * место снимка. Масштабируется он от своего центра, поэтому точку приходится
 * удерживать встречным смещением.
 */
function zoomAt(factor, clientX, clientY) {
    const img = image();

    if (! img) {
        return;
    }

    const next = Math.min(MAX_SCALE, Math.max(1, scale.value * factor));
    const applied = next / scale.value;

    if (applied === 1) {
        return;
    }

    const box = img.getBoundingClientRect();
    const dx = clientX - (box.left + box.width / 2);
    const dy = clientY - (box.top + box.height / 2);

    offset.x -= dx * (applied - 1);
    offset.y -= dy * (applied - 1);
    scale.value = next;

    clamp();
}

function onWheel(event) {
    // Шаг зависит от того, насколько крутнули: у мыши это щелчки по сотне
    // точек, у тачпада — плавные единицы.
    zoomAt(Math.exp(-event.deltaY * 0.002), event.clientX, event.clientY);
}

function spread() {
    const [first, second] = [...pointers.values()];

    return {
        distance: Math.hypot(first.x - second.x, first.y - second.y),
        x: (first.x + second.x) / 2,
        y: (first.y + second.y) / 2,
    };
}

function onPointerDown(event) {
    try {
        // Захват нужен, чтобы палец, ушедший за край снимка, продолжал тащить.
        // Браузер вправе отказать — тогда просто тащим, пока курсор над ним.
        image()?.setPointerCapture?.(event.pointerId);
    } catch {
        // Отказ не повод терять нажатие.
    }

    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pointers.size === 2) {
        pinch = { ...spread(), scale: scale.value };
    }
}

function onPointerMove(event) {
    if (! pointers.has(event.pointerId)) {
        return;
    }

    const previous = pointers.get(event.pointerId);

    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pointers.size >= 2 && pinch) {
        const now = spread();

        zoomAt((now.distance / pinch.distance) * pinch.scale / scale.value, now.x, now.y);

        return;
    }

    // Один палец или мышь: тащим, но только когда есть что тащить.
    if (scale.value > 1) {
        offset.x += event.clientX - previous.x;
        offset.y += event.clientY - previous.y;

        clamp();
    }
}

function onPointerUp(event) {
    pointers.delete(event.pointerId);

    if (pointers.size < 2) {
        pinch = null;
    }
}

function onKey(event) {
    if (event.key === 'Escape') {
        closeZoom();
    }
}

watch(shown, (open) => {
    reset();

    // Страница под слоем не прокручивается: закрыв снимок, человек должен
    // оказаться там же, где его оставил.
    document.body.style.overflow = open ? 'hidden' : '';

    if (open) {
        window.addEventListener('keydown', onKey);
    } else {
        window.removeEventListener('keydown', onKey);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <!-- Щелчок мимо снимка закрывает; по самому снимку — нет: там его
             двигают и увеличивают. -->
        <div
            v-if="shown"
            class="image-zoom"
            role="dialog"
            aria-modal="true"
            :aria-label="shown.title"
            @click.self="closeZoom()"
        >
            <div class="image-zoom__bar">
                <span class="text-truncate">{{ shown.title }}</span>
                <span v-if="shown.subtitle" class="badge text-bg-light flex-shrink-0">
                    {{ shown.subtitle }}
                </span>
                <button
                    type="button"
                    class="btn-close btn-close-white ms-auto flex-shrink-0"
                    :aria-label="t('item.close')"
                    @click="closeZoom()"
                ></button>
            </div>

            <div ref="frame" class="image-zoom__frame" @click.self="closeZoom()">
                <AssemblyImage
                    v-if="shown.kind === 'assembly'"
                    ref="picture"
                    :id="shown.id"
                    :has-image="shown.hasImage"
                    :alt="shown.title"
                    class="image-zoom__picture"
                    :class="{ 'image-zoom__picture--grabbable': scale > 1 }"
                    :style="style"
                    @wheel.prevent="onWheel"
                    @pointerdown="onPointerDown"
                    @pointermove="onPointerMove"
                    @pointerup="onPointerUp"
                    @pointercancel="onPointerUp"
                />
                <ItemImage
                    v-else
                    ref="picture"
                    :type="shown.type"
                    :id="shown.id"
                    :color-id="shown.colorId"
                    :alt="shown.title"
                    class="image-zoom__picture"
                    :class="{ 'image-zoom__picture--grabbable': scale > 1 }"
                    :style="style"
                    @wheel.prevent="onWheel"
                    @pointerdown="onPointerDown"
                    @pointermove="onPointerMove"
                    @pointerup="onPointerUp"
                    @pointercancel="onPointerUp"
                />
            </div>
        </div>
    </Teleport>
</template>
