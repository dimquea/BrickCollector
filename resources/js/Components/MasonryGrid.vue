<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { masonry } from '@/support/cards';

/**
 * Сетка карточек разной высоты — с порядком по рядам.
 *
 * Прежде её делали колонки CSS, и браузер заполнял первую колонку сверху донизу,
 * потом вторую: третья карточка оказывалась под первой, а не рядом с ней, и
 * список, упорядоченный по году, читался столбиками. Порядок по рядам CSS даёт
 * только в обычной сетке, где ряд растягивается до самой высокой карточки, —
 * а у карточек со снимками высоты разные, и там вышли бы пустоты.
 *
 * Поэтому карточки раскладываются по колонкам по кругу: первая в первую
 * колонку, вторая во вторую, и так далее. Первый ряд — первые N карточек,
 * второй — следующие N. Колонки внизу кончаются на разной высоте — это цена.
 *
 * Сколько колонок, по-прежнему решает CSS: размер карточки и ширина экрана
 * задают переменную --masonry-columns, а здесь её только читают — при
 * изменении ширины пересчитывается сама раскладка, а не правила.
 */
const props = defineProps({
    items: { type: Array, required: true },
    size: { type: Object, default: () => ({}) },
    /** Ключ карточки; по умолчанию — её место в списке. */
    itemKey: { type: Function, default: (item, index) => index },
});

const el = ref(null);
const count = ref(1);
let observer = null;

function measure() {
    if (! el.value) {
        return;
    }

    const value = parseInt(getComputedStyle(el.value).getPropertyValue('--masonry-columns'), 10);

    count.value = Number.isFinite(value) && value > 0 ? value : 1;
}

const columns = computed(() => {
    const result = Array.from({ length: count.value }, () => []);

    props.items.forEach((item, index) => {
        result[index % count.value].push({ item, index, key: props.itemKey(item, index) });
    });

    return result;
});

onMounted(() => {
    measure();

    // Ширина меняется не только с окном: открывшаяся полоса прокрутки тоже
    // сужает список.
    observer = new ResizeObserver(measure);
    observer.observe(el.value);
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div ref="el" :class="masonry(size)">
        <div v-for="(column, c) in columns" :key="c" class="masonry__column">
            <template v-for="cell in column" :key="cell.key">
                <slot :item="cell.item" :index="cell.index" />
            </template>
        </div>
    </div>
</template>
