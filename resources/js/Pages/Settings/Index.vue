<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { locale, t } from '@/i18n';

const page = usePage();
const supported = computed(() => page.props.supportedLocales ?? []);

function switchLocale(event) {
    const next = event.target.value;

    if (next !== locale.value) {
        router.post('/locale', { locale: next }, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="t('settings.title')" />

    <AppLayout>
        <h1 class="h4 mb-3">{{ t('settings.title') }}</h1>

        <div id="settingsAccordion" class="accordion">
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button
                        class="accordion-button"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#settingsAppearance"
                    >
                        <i class="mdi mdi-palette-outline me-2"></i>
                        {{ t('settings.appearance') }}
                    </button>
                </h2>
                <div
                    id="settingsAppearance"
                    class="accordion-collapse collapse show"
                    data-bs-parent="#settingsAccordion"
                >
                    <div class="accordion-body">
                        <p class="text-body-secondary small">{{ t('settings.appearance_hint') }}</p>

                        <div class="row">
                            <div class="col-12 col-md-4">
                                <label for="localeSelect" class="form-label">
                                    {{ t('locale.label') }}
                                </label>
                                <!-- Two options: a plain select. The SearchSelect
                                     wrapper is for lists longer than ten. -->
                                <select
                                    id="localeSelect"
                                    class="form-select"
                                    :value="locale"
                                    @change="switchLocale"
                                >
                                    <option v-for="code in supported" :key="code" :value="code">
                                        {{ t(`locale.${code}`) }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
