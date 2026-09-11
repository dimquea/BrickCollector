<script setup>
import { url } from '@/support/base';
import { computed, reactive, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import Link from '@/Components/AppLink.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import ItemImage from '@/Components/ItemImage.vue';
import EntryStatusBadges from '@/Components/EntryStatusBadges.vue';
import { debounce } from '@/support/debounce';
import { masonry } from '@/support/cards';
import { t, tChoice } from '@/i18n';

const props = defineProps({
    cardSize: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    entries: { type: Object, required: true },
    itemTypes: { type: Array, default: () => [] },
    themes: { type: Array, default: () => [] },
    years: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    tags: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash);

const form = reactive({
    q: props.filters.q ?? '',
    type: props.filters.type ?? null,
    theme_id: props.filters.theme_id ?? null,
    year: props.filters.year ?? null,
    status_id: props.filters.status_id ?? null,
    tag_id: props.filters.tag_id ?? null,
    incomplete: Boolean(props.filters.incomplete),
    missing_figs: Boolean(props.filters.missing_figs),
});

function submit() {
    router.get(url('/sets'), clean(), { preserveState: true, preserveScroll: true, replace: true });
}

/** Empty filters stay out of the URL so a bare /sets is a clean link. */
function clean() {
    return Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== null && value !== '' && value !== false),
    );
}

function reset() {
    Object.assign(form, {
        q: '', type: null, theme_id: null, year: null,
        status_id: null, tag_id: null, incomplete: false, missing_figs: false,
    });
}

watch(() => form.q, debounce(submit, 300));
watch(
    () => [form.type, form.theme_id, form.year, form.status_id, form.tag_id, form.incomplete, form.missing_figs],
    submit,
);

const themeOptions = computed(() => props.themes.map((theme) => ({ value: theme.id, label: theme.path })));
const yearOptions = computed(() => props.years.map((year) => ({ value: year, label: String(year) })));

/**
 * A filter with one option cannot narrow anything: owning only sets makes a
 * type filter offering "Set" pure decoration. Shown from two options up.
 */
const shows = (list) => list.length > 1;
</script>

<template>
    <Head :title="t('nav.sets')" />

    <AppLayout>
        <div v-if="flash?.message" class="alert alert-success d-flex align-items-center gap-2">
            <i class="mdi mdi-check-circle-outline"></i>
            <span>{{ flash.message }}</span>
        </div>

        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
            <h1 class="h4 mb-0">{{ t('nav.sets') }}</h1>
            <span class="text-body-secondary small">{{ tChoice('sets.found', entries.total) }}</span>
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

                    <div v-if="shows(itemTypes)" class="col-6 col-lg-2">
                        <label for="type" class="form-label">{{ t('catalog.type') }}</label>
                        <select id="type" v-model="form.type" class="form-select">
                            <option :value="null">{{ t('catalog.any') }}</option>
                            <option v-for="type in itemTypes" :key="type.code" :value="type.code">
                                {{ type.name }}
                            </option>
                        </select>
                    </div>

                    <div v-if="shows(themes)" class="col-12 col-lg-3">
                        <label for="theme" class="form-label">{{ t('catalog.theme') }}</label>
                        <SearchSelect
                            id="theme"
                            v-model="form.theme_id"
                            :options="themeOptions"
                            :placeholder="t('catalog.any')"
                        />
                    </div>

                    <div v-if="shows(years)" class="col-6 col-lg-2">
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

                    <div v-if="shows(statuses)" class="col-12 col-lg-3">
                        <label for="status" class="form-label">{{ t('collection.statuses') }}</label>
                        <select id="status" v-model="form.status_id" class="form-select">
                            <option :value="null">{{ t('catalog.any') }}</option>
                            <option v-for="status in statuses" :key="status.id" :value="status.id">
                                {{ status.name }}
                            </option>
                        </select>
                    </div>

                    <div v-if="tags.length" class="col-12 col-lg-3">
                        <label for="tag" class="form-label">{{ t('collection.tags') }}</label>
                        <select id="tag" v-model="form.tag_id" class="form-select">
                            <option :value="null">{{ t('catalog.any') }}</option>
                            <option v-for="tag in tags" :key="tag.id" :value="tag.id">{{ tag.name }}</option>
                        </select>
                    </div>

                    <div class="col-12 col-lg-6 d-flex align-items-end gap-3 flex-wrap">
                        <div class="form-check">
                            <input
                                id="incomplete"
                                v-model="form.incomplete"
                                class="form-check-input"
                                type="checkbox"
                            />
                            <label class="form-check-label" for="incomplete">
                                {{ t('collection.incomplete') }}
                            </label>
                        </div>
                        <div class="form-check">
                            <input
                                id="missingFigs"
                                v-model="form.missing_figs"
                                class="form-check-input"
                                type="checkbox"
                            />
                            <label class="form-check-label" for="missingFigs">
                                {{ t('collection.missing_figs') }}
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p v-if="entries.data.length === 0" class="text-body-secondary">
            {{ t('collection.empty') }}
            <Link href="/catalog">{{ t('collection.empty_hint') }}</Link>
        </p>

        <div :class="masonry(cardSize)">
            <div v-for="entry in entries.data" :key="entry.id" class="card shadow-sm">
                <Link
                    :href="`/sets/${entry.id}`"
                    class="card-header d-flex align-items-center gap-2 text-truncate text-decoration-none"
                >
                    <span class="badge text-bg-secondary flex-shrink-0">{{ entry.item_id }}</span>
                    <span class="text-truncate" :title="entry.name">{{ entry.name }}</span>
                </Link>

                <Link :href="`/sets/${entry.id}`">
                    <ItemImage
                        :type="entry.type"
                        :id="entry.item_id"
                        :color-id="entry.image_color_id"
                        :alt="entry.name"
                        class="card-img-top p-2"
                    />
                </Link>

                <div class="card-footer d-flex flex-wrap gap-1 align-items-center">
                    <EntryStatusBadges
                        :status-codes="entry.status_codes"
                        :incomplete="entry.flag_incomplete"
                        :missing-figs="entry.flag_missing_figs"
                    />

                    <span
                        v-for="tag in entry.tags"
                        :key="tag.name"
                        class="badge"
                        :class="`text-bg-${tag.color}`"
                    >
                        {{ tag.name }}
                    </span>
                </div>
            </div>
        </div>

        <nav v-if="entries.last_page > 1" class="mt-4">
            <ul class="pagination justify-content-center flex-wrap">
                <li
                    v-for="link in entries.links"
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
