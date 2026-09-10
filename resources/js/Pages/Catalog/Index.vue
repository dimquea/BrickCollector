<script setup>
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { debounce } from '@/support/debounce';
import AppLayout from '@/Layouts/AppLayout.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import ItemImage from '@/Components/ItemImage.vue';
import { t, tChoice } from '@/i18n';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    results: { type: Object, required: true },
    itemTypes: { type: Array, default: () => [] },
    themes: { type: Array, default: () => [] },
    years: { type: Array, default: () => [] },
});

const form = reactive({
    q: props.filters.q ?? '',
    type: props.filters.type ?? null,
    theme_id: props.filters.theme_id ?? null,
    year: props.filters.year ?? null,
    has_inventory: Boolean(props.filters.has_inventory),
});

function submit() {
    router.get('/catalog', clean(), { preserveState: true, preserveScroll: true, replace: true });
}

/** Empty filters stay out of the URL so a bare /catalog is a clean link. */
function clean() {
    return Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== null && value !== '' && value !== false),
    );
}

function reset() {
    Object.assign(form, { q: '', type: null, theme_id: null, year: null, has_inventory: false });
}

// Typing fires a request per keystroke otherwise.
watch(() => form.q, debounce(submit, 300));
watch(() => [form.type, form.theme_id, form.year, form.has_inventory], submit);

const themeOptions = props.themes.map((theme) => ({ value: theme.id, label: theme.path }));
</script>

<template>
    <Head :title="t('catalog.title')" />

    <AppLayout>
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
            <h1 class="h4 mb-0">{{ t('catalog.title') }}</h1>
            <span class="text-body-secondary small">
                {{ tChoice('catalog.found', results.total) }}
            </span>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-lg-4">
                        <label for="q" class="form-label">{{ t('catalog.query') }}</label>
                        <input
                            id="q"
                            v-model="form.q"
                            type="search"
                            class="form-control"
                            :placeholder="t('catalog.query_hint')"
                        />
                    </div>

                    <div class="col-6 col-lg-2">
                        <label for="type" class="form-label">{{ t('catalog.type') }}</label>
                        <select id="type" v-model="form.type" class="form-select">
                            <option :value="null">{{ t('catalog.any') }}</option>
                            <option v-for="type in itemTypes" :key="type.code" :value="type.code">
                                {{ type.name }}
                            </option>
                        </select>
                    </div>

                    <div class="col-12 col-lg-3">
                        <label for="theme" class="form-label">{{ t('catalog.theme') }}</label>
                        <SearchSelect
                            id="theme"
                            v-model="form.theme_id"
                            :options="themeOptions"
                            :placeholder="t('catalog.any')"
                        />
                    </div>

                    <div class="col-6 col-lg-2">
                        <label for="year" class="form-label">{{ t('catalog.year') }}</label>
                        <SearchSelect
                            id="year"
                            v-model="form.year"
                            :options="years.map((y) => ({ value: y, label: String(y) }))"
                            :placeholder="t('catalog.any')"
                        />
                    </div>

                    <div class="col-12 col-lg-1 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-secondary w-100" @click="reset">
                            {{ t('catalog.reset') }}
                        </button>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input
                                id="has_inventory"
                                v-model="form.has_inventory"
                                class="form-check-input"
                                type="checkbox"
                            />
                            <label class="form-check-label" for="has_inventory">
                                {{ t('catalog.only_with_inventory') }}
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p v-if="results.data.length === 0" class="text-body-secondary">
            {{ t('catalog.nothing_found') }}
        </p>

        <div class="masonry">
            <div v-for="item in results.data" :key="`${item.type}/${item.id}`" class="card shadow-sm">
                <div class="card-header d-flex align-items-center gap-2 text-truncate">
                    <span class="badge text-bg-secondary flex-shrink-0">{{ item.id }}</span>
                    <span class="text-truncate" :title="item.name">{{ item.name }}</span>
                </div>

                <Link :href="`/catalog/${item.type}/${encodeURIComponent(item.id)}`">
                    <ItemImage
                        :type="item.type"
                        :id="item.id"
                        :color-id="item.image_color_id"
                        :has-image="item.has_image"
                        :alt="item.name"
                        class="card-img-top p-2"
                    />
                </Link>

                <div class="card-footer d-flex flex-wrap gap-1 align-items-center">
                    <span v-if="item.year" class="badge text-bg-light">{{ item.year }}</span>
                    <span v-if="item.theme" class="badge text-bg-light text-truncate" :title="item.theme">
                        {{ item.theme }}
                    </span>
                    <i
                        v-if="item.has_inventory"
                        class="mdi mdi-format-list-bulleted text-success ms-auto"
                        :title="t('catalog.has_inventory')"
                    ></i>
                </div>
            </div>
        </div>

        <nav v-if="results.last_page > 1" class="mt-4">
            <ul class="pagination justify-content-center flex-wrap">
                <li
                    v-for="link in results.links"
                    :key="link.label"
                    class="page-item"
                    :class="{ active: link.active, disabled: !link.url }"
                >
                    <Link
                        v-if="link.url"
                        class="page-link"
                        :href="link.url"
                        preserve-scroll
                        v-html="link.label"
                    />
                    <span v-else class="page-link" v-html="link.label" />
                </li>
            </ul>
        </nav>
    </AppLayout>
</template>
