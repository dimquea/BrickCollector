<script setup>
import { t } from '@/i18n';

defineProps({
    lot: { type: Object, required: true },
});
</script>

<template>
    <span class="d-inline-flex gap-1">
        <!-- A spare is not a problem: it is an extra piece in the box. -->
        <span v-if="lot.is_extra" class="badge text-bg-light border" :title="t('lot.extra_hint')">
            {{ t('lot.extra') }}
        </span>
        <span v-if="lot.is_alternate" class="badge text-bg-info" :title="t('lot.alternate_hint')">
            {{ t('lot.alternate') }}
        </span>
        <span v-if="lot.is_counterpart" class="badge text-bg-secondary" :title="t('lot.counterpart_hint')">
            {{ t('lot.counterpart') }}
        </span>
    </span>
</template>
