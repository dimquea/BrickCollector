<script setup>
import { Head } from '@inertiajs/vue3';
import Link from '@/Components/AppLink.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { locale, t, tChoice } from '@/i18n';

defineProps({
    catalogItems: { type: Number, default: 0 },
    entryCount: { type: Number, default: 0 },
});

const number = (value) => new Intl.NumberFormat(locale.value).format(value);
</script>

<template>
    <Head :title="t('home.title')" />

    <AppLayout>
        <div class="card shadow-sm">
            <div class="card-body">
                <h1 class="h4 card-title">{{ t('home.title') }}</h1>
                <p class="text-body-secondary mb-3">{{ t('home.tagline') }}</p>

                <!-- Пока справочника нет, ни искать, ни добавлять нечего: это
                     единственное, что стоит сказать на этой странице. Раньше
                     плашка висела всегда и на заполненной установке врала. -->
                <div
                    v-if="catalogItems === 0"
                    class="alert alert-warning d-flex align-items-center gap-2 mb-0"
                    role="alert"
                >
                    <i class="mdi mdi-database-alert-outline fs-5"></i>
                    <span>{{ t('home.catalog_empty') }}</span>
                </div>

                <div v-else class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="badge text-bg-light border">
                        {{ t('home.catalog_size') }}: {{ number(catalogItems) }}
                    </span>

                    <span v-if="entryCount" class="badge text-bg-success">
                        {{ tChoice('sets.found', entryCount) }}
                    </span>

                    <Link :href="entryCount ? '/sets' : '/catalog'" class="btn btn-sm btn-primary ms-auto">
                        {{ entryCount ? t('nav.sets') : t('collection.empty_hint') }}
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
