<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
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
 * Картинка показывается в свою величину и не растягивается: в кэше лежит то,
 * что отдал BrickLink, и растянутая сверх собственного размера она станет
 * только мутнее. Больше экрана не показать — отсюда и потолок в 95 % окна.
 *
 * Рисуют её те же ItemImage и AssemblyImage, что и в списках: правила «кэш,
 * потом источник, потом заглушка» остаются в одном месте.
 */

/**
 * Ширина увеличенного вида в точках, или null — показываем как есть.
 *
 * Двойной щелчок удваивает собственный размер снимка, а не нарисованный:
 * нарисованный мог быть урезан высотой окна, и «вдвое» от него оказалось бы
 * меньше, чем вдвое. Что не влезло — доступно прокруткой слоя.
 */
const scaled = ref(null);

function toggle(event) {
    scaled.value = scaled.value ? null : event.target.naturalWidth * 2;
}

function onKey(event) {
    if (event.key === 'Escape') {
        closeZoom();
    }
}

watch(shown, (open) => {
    scaled.value = null;

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
        <!-- Щелчок мимо снимка закрывает; по самому снимку — нет: там живёт
             двойной щелчок, и закрытие отнимало бы у него первый из двух. -->
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

            <div class="image-zoom__frame" @click.self="closeZoom()">
                <AssemblyImage
                    v-if="shown.kind === 'assembly'"
                    :id="shown.id"
                    :has-image="shown.hasImage"
                    :alt="shown.title"
                    class="image-zoom__picture"
                    :class="{ 'image-zoom__picture--scaled': scaled }"
                    :style="scaled ? { width: `${scaled}px` } : null"
                    @dblclick="toggle"
                />
                <ItemImage
                    v-else
                    :type="shown.type"
                    :id="shown.id"
                    :color-id="shown.colorId"
                    :alt="shown.title"
                    class="image-zoom__picture"
                    :class="{ 'image-zoom__picture--scaled': scaled }"
                    :style="scaled ? { width: `${scaled}px` } : null"
                    @dblclick="toggle"
                />
            </div>
        </div>
    </Teleport>
</template>
