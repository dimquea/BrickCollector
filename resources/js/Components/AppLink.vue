<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { url } from '@/support/base';

/**
 * Ссылка внутри приложения.
 *
 * Отличается от инертиевской одним: путь, начинающийся со слеша, дополняется
 * префиксом, под которым приложение видно браузеру. В обычной установке
 * префикс пустой и ничего не меняется; под Ingress в аддоне Home Assistant без
 * него ломается всё, что не левый клик, — открыть в новой вкладке, скопировать
 * адрес, увидеть его в строке состояния.
 *
 * Готовые абсолютные адреса — постранички, например, — сервер уже собрал
 * правильно, их не трогаем.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    href: { type: String, required: true },
});

const target = computed(() => (props.href.startsWith('/') ? url(props.href) : props.href));
</script>

<template>
    <Link v-bind="$attrs" :href="target">
        <slot />
    </Link>
</template>
