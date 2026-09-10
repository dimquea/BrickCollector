<script setup>
import { computed, reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import { debounce } from '@/support/debounce';
import { t, tChoice } from '@/i18n';

const props = defineProps({
    filters: { type: Object, default: () => ({}) },
    figures: { type: Object, required: true },
    themes: { type: Array, default: () => [] },
    years: { type: Array, default: () => [] },
    tags: { type: Array, default: () => [] },
});

const form = reactive({
    q: props.filters.q ?? '',
    theme_id: props.filters.theme_id ?? null,
    year: props.filters.year ?? null,
    tag_id: props.filters.tag_id ?? null,
    placement: props.filters.placement ?? null,
});

function submit() {
    router.get('/minifigures', clean(), { preserveState: true, preserveScroll: true, replace: true });
}

function clean() {
    return Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== null && value !== ''),
    );
}

function reset() {
    Object.assign(form, { q: '', theme_id: null, year: null, tag_id: null, placement: null });
}

watch(() => form.q, debounce(submit, 300));
watch(() => [form.theme_id, form.year, form.tag_id, form.placement], submit);

/**
 * A figure is the same figure whether it came in a set or on its own, so the
 * list holds one card for it either way and this picks which to look at.
 */
function togglePlacement(value) {
    form.placement = form.placement === value ? null : value;
}

const themeOptions = computed(() => props.themes.map((theme) => ({ value: theme.id, label: theme.path })));
const yearOptions = computed(() => props.years.map((year) => ({ value: year, label: String(year) })));

const href = (figure) => `/minifigures/${encodeURIComponent(figure.item_id)}`;
</script>

<template>
    <Head :title="t('nav.minifigures')" />

    <AppLayout>
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
            <h1 class="h4 mb-0">{{ t('nav.minifigures') }}</h1>
            <span class="text-body-secondary small">{{ tChoice('minifigures.found', figures.total) }}</span>
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

                    <div v-if="themes.length > 1" class="col-12 col-lg-3">
                        <label for="theme" class="form-label">{{ t('catalog.theme') }}</label>
                        <SearchSelect
                            id="theme"
                            v-model="form.theme_id"
                            :options="themeOptions"
                            :placeholder="t('catalog.any')"
                        />
                    </div>

                    <div v-if="years.length > 1" class="col-6 col-lg-2">
                        <label for="year" class="form-label">{{ t('catalog.year') }}</label>
                        <SearchSelect
                            id="year"
                            v-model="form.year"
                            :options="yearOptions"
                            :placeholder="t('catalog.any')"
                        />
                    </div>

                    <div class="col-12 col-lg-2 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-secondary w-100 text-nowrap" @click="reset">
                            {{ t('catalog.reset') }}
                        </button>
                    </div>

                    <div class="col-12 col-lg-6">
                        <span class="form-label d-block">{{ t('minifigures.placement') }}</span>
                        <div class="btn-group" role="group">
                            <button
                                type="button"
                                class="btn btn-sm"
                                :class="form.placement === 'set' ? 'btn-primary' : 'btn-outline-secondary'"
                                @click="togglePlacement('set')"
                            >
                                {{ t('minifigures.in_sets') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-sm"
                                :class="form.placement === 'loose' ? 'btn-primary' : 'btn-outline-secondary'"
                                @click="togglePlacement('loose')"
                            >
                                {{ t('minifigures.loose') }}
                            </button>
                        </div>
                    </div>

                    <div v-if="tags.length" class="col-12 col-lg-6">
                        <label for="tag" class="form-label">{{ t('collection.tags') }}</label>
                        <select id="tag" v-model="form.tag_id" class="form-select">
                            <option :value="null">{{ t('catalog.any') }}</option>
                            <option v-for="tag in tags" :key="tag.id" :value="tag.id">{{ tag.name }}</option>
                        </select>
                        <div class="form-text">{{ t('minifigures.tag_hint') }}</div>
                    </div>
                </div>
            </div>
        </div>

        <p v-if="figures.data.length === 0" class="text-body-secondary">
            {{ t('collection.empty') }}
            <Link href="/catalog">{{ t('collection.empty_hint') }}</Link>
        </p>

        <div class="masonry">
            <div v-for="figure in figures.data" :key="figure.item_id" class="card shadow-sm">
                <Link
                    :href="href(figure)"
                    class="card-header d-flex align-items-center gap-2 text-truncate text-decoration-none"
                >
                    <span class="badge text-bg-secondary flex-shrink-0">{{ figure.item_id }}</span>
                    <span class="text-truncate" :title="figure.name">{{ figure.name }}</span>
                </Link>

                <Link :href="href(figure)">
                    <ItemImage
                        type="M"
                        :id="figure.item_id"
                        :color-id="figure.image_color_id"
                        :alt="figure.name"
                        class="card-img-top p-2"
                    />
                </Link>

                <div class="card-footer d-flex flex-wrap gap-1 align-items-center">
                    <span class="badge text-bg-success">{{ figure.total }}</span>
                    <span v-if="figure.entries" class="badge text-bg-light border">
                        {{ tChoice('minifigures.in_sets_badge', figure.entries) }}
                    </span>
                    <span v-if="figure.loose" class="badge text-bg-light border">
                        {{ tChoice('minifigures.loose_badge', figure.loose) }}
                    </span>
                    <span v-if="figure.lost" class="badge text-bg-warning">
                        <i class="mdi mdi-alert-outline"></i> {{ figure.lost }}
                    </span>
                </div>
            </div>
        </div>

        <nav v-if="figures.last_page > 1" class="mt-4">
            <ul class="pagination justify-content-center flex-wrap">
                <li
                    v-for="link in figures.links"
                    :key="link.label"
                    class="page-item"
                    :class="{ active: link.active, disabled: !link.url }"
                >
                    <Link v-if="link.url" class="page-link" :href="link.url" preserve-scroll v-html="link.label" />
                    <span v-else class="page-link" v-html="link.label" />
                </li>
            </ul>
        </nav>
    </AppLayout>
</template>
