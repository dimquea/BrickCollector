<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { t, tChoice } from '@/i18n';

defineProps({
    partCount: { type: Number, default: 0 },
});
</script>

<template>
    <Head :title="t('home.title')" />

    <AppLayout>
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 card-title">{{ t('home.title') }}</h1>
                <p class="text-body-secondary mb-3">{{ t('home.tagline') }}</p>

                <div class="alert alert-warning d-flex align-items-center gap-2 mb-0" role="alert">
                    <i class="mdi mdi-database-alert-outline fs-5"></i>
                    <span>
                        {{ t('home.catalog_empty') }}
                        <span class="ms-1 text-body-secondary">({{ tChoice('parts_counted', partCount) }})</span>
                    </span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
