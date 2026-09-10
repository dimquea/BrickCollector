<script setup>
defineProps({
    rgb: { type: String, default: null },
    name: { type: String, default: null },
});
</script>

<template>
    <span class="d-inline-flex align-items-center gap-1">
        <span
            class="rounded-circle border flex-shrink-0"
            style="width: 0.75rem; height: 0.75rem"
            :style="{ background: rgb ? `#${rgb}` : 'transparent' }"
        />
        <span class="text-truncate">{{ name }}</span>
    </span>
</template>
