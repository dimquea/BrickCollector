<script setup>
import { url } from '@/support/base';
import { ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import DictionaryEditor from '@/Components/DictionaryEditor.vue';
import LinkEditor from '@/Components/LinkEditor.vue';
import ListAppearance from '@/Components/ListAppearance.vue';
import { notify } from '@/support/toasts';
import { locale, t } from '@/i18n';

const props = defineProps({
    dictionaries: { type: Object, required: true },
    colors: { type: Array, default: () => [] },
    currency: { type: String, default: 'RUB' },
    links: { type: Array, default: () => [] },
    appearance: { type: Object, default: () => ({ lists: [] }) },
    catalog: { type: Object, default: () => ({}) },
});

const page = usePage();
const supported = ref(page.props.supportedLocales ?? []);
const currency = ref(props.currency);

function switchLocale(event) {
    const next = event.target.value;

    if (next !== locale.value) {
        // A language change swaps every string on the page, so this one does
        // go through the router.
        router.post(url('/locale'), { locale: next }, { preserveScroll: true });
    }
}

async function saveCurrency() {
    try {
        const { data } = await axios.patch(url('/settings'), { currency: currency.value });
        notify(data.message, 'success', 2000);
    } catch (error) {
        currency.value = props.currency;
        notify(error.response?.data?.message ?? t('errors.save_failed'));
    }
}

const sections = [
    { key: 'sources', kind: 'sources', hasActive: true },
    { key: 'storages', kind: 'storages', hasActive: true },
    { key: 'tags', kind: 'tags', hasColor: true, hasVisibility: true },
    { key: 'statuses', kind: 'statuses', note: 'dictionaries.statuses_note' },
];
</script>

<template>
    <Head :title="t('settings.title')" />

    <AppLayout>
        <h1 class="h4 mb-3">{{ t('settings.title') }}</h1>

        <div class="accordion">
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
                <div id="settingsAppearance" class="accordion-collapse collapse show">
                    <div class="accordion-body">
                        <p class="text-body-secondary small">{{ t('settings.appearance_hint') }}</p>

                        <div class="row g-3">
                            <div class="col-12 col-md-4">
                                <label for="localeSelect" class="form-label">{{ t('locale.label') }}</label>
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

                            <div class="col-12 col-md-4">
                                <label for="currency" class="form-label">{{ t('settings.currency') }}</label>
                                <input
                                    id="currency"
                                    v-model="currency"
                                    type="text"
                                    class="form-control text-uppercase"
                                    maxlength="3"
                                    @blur="saveCurrency"
                                />
                                <div class="form-text">{{ t('settings.currency_hint') }}</div>
                            </div>
                        </div>

                        <h3 class="h6 mt-4">{{ t('settings.lists') }}</h3>

                        <ListAppearance :lists="appearance.lists" />
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button
                        class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#settingsDictionaries"
                    >
                        <i class="mdi mdi-format-list-bulleted-type me-2"></i>
                        {{ t('settings.dictionaries') }}
                    </button>
                </h2>
                <div id="settingsDictionaries" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <div v-for="section in sections" :key="section.key" class="mb-4">
                            <h3 class="h6">{{ t(`dictionaries.${section.key}`) }}</h3>
                            <p class="text-body-secondary small mb-2">
                                {{ t(`dictionaries.${section.key}_hint`) }}
                            </p>

                            <DictionaryEditor
                                :kind="section.kind"
                                :rows="dictionaries[section.key]"
                                :colors="colors"
                                :has-color="!!section.hasColor"
                                :has-visibility="!!section.hasVisibility"
                                :has-active="!!section.hasActive"
                            />

                            <div
                                v-if="section.note"
                                class="alert alert-light border small d-flex gap-2 mb-0"
                            >
                                <i class="mdi mdi-information-outline flex-shrink-0"></i>
                                <span>{{ t(section.note) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button
                        class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#settingsLinks"
                    >
                        <i class="mdi mdi-open-in-new me-2"></i>
                        {{ t('links.title') }}
                    </button>
                </h2>
                <div id="settingsLinks" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <p class="text-body-secondary small">{{ t('links.hint') }}</p>

                        <LinkEditor :rows="links" />
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button
                        class="accordion-button collapsed"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#settingsCatalog"
                    >
                        <i class="mdi mdi-database-sync-outline me-2"></i>
                        {{ t('settings.catalog') }}
                    </button>
                </h2>
                <div id="settingsCatalog" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <p class="mb-1">
                            {{ t('settings.catalog_items') }}:
                            <strong>{{ (catalog.items ?? 0).toLocaleString(locale) }}</strong>
                        </p>
                        <p class="text-body-secondary small mb-0">{{ t('settings.catalog_hint') }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
