<script setup>
import axios from 'axios';
import { url } from '@/support/base';
import { computed, reactive, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { notify } from '@/support/toasts';
import Link from '@/Components/AppLink.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import SortControl from '@/Components/SortControl.vue';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import { debounce } from '@/support/debounce';
import { masonry } from '@/support/cards';
import { t, tChoice } from '@/i18n';

/**
 * Желаемое: список без меты.
 *
 * Карточка ведёт в справочник, а не на свою страницу: про желание сказать
 * нечего, кроме самого предмета, и всё, что о нём известно, уже есть там.
 */
const props = defineProps({
    cardSize: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    sort: { type: Object, default: () => ({}) },
    items: { type: Object, required: true },
    itemTypes: { type: Array, default: () => [] },
    themes: { type: Array, default: () => [] },
    years: { type: Array, default: () => [] },
});

const page = usePage();
const flash = computed(() => page.props.flash);

const sortOptions = computed(() => [
    { value: 'id', label: t('sort.id') },
    { value: 'name', label: t('sort.name') },
    { value: 'year', label: t('sort.year') },
]);

// Обычный порядок раздела — по добавлению — показывается пустым полем.
const chosenSort = sortOptions.value.some((option) => option.value === props.sort.by) ? props.sort.by : null;

const form = reactive({
    q: props.filters.q ?? '',
    type: props.filters.type ?? null,
    theme_id: props.filters.theme_id ?? null,
    year: props.filters.year ?? null,
    sort: chosenSort,
    dir: props.sort.dir ?? 'desc',
});

function submit() {
    router.get(url('/wishlist'), clean(), { preserveState: true, preserveScroll: true, replace: true });
}

function clean() {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== null && value !== ''),
    );

    // Последнее добавленное сверху — обычный порядок этого раздела.
    if (query.dir === (query.sort ? 'asc' : 'desc')) {
        delete query.dir;
    }

    return query;
}

function reset() {
    Object.assign(form, { q: '', type: null, theme_id: null, year: null, sort: null, dir: 'desc' });
}

watch(() => form.q, debounce(submit, 300));
watch(() => [form.type, form.theme_id, form.year, form.sort, form.dir], submit);

const typeOptions = computed(() => props.itemTypes.map((type) => ({ value: type.code, label: type.name })));
const themeOptions = computed(() => props.themes.map((theme) => ({ value: theme.id, label: theme.path })));
const yearOptions = computed(() => props.years.map((year) => ({ value: year, label: String(year) })));

const href = (item) => `/catalog/${item.type}/${encodeURIComponent(item.id)}`;

/**
 * Убирает желание, не сходя со страницы.
 *
 * Запрос идёт в стороне от навигации, а список потом перечитывается частично:
 * возврат «назад» здесь промахивался — под Home Assistant в сессии предыдущей
 * страницы нет, и он выбрасывал на главную.
 */
async function remove(item) {
    try {
        const { data } = await axios.delete(url(`/wishlist/${item.wish_id}`));

        notify(data.message, 'success', 2000);
        router.reload({ only: ['items'], preserveScroll: true });
    } catch (error) {
        notify(error.response?.data?.message ?? t('errors.save_failed'));
    }
}

/** Фильтр с одним вариантом ничего не сужает. */
const shows = (list) => list.length > 1;
</script>

<template>
    <Head :title="t('wishlist.title')" />

    <AppLayout>
        <div v-if="flash?.message" class="alert alert-success d-flex align-items-center gap-2">
            <i class="mdi mdi-check-circle-outline"></i>
            <span>{{ flash.message }}</span>
        </div>

        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
            <h1 class="h4 mb-0">{{ t('wishlist.title') }}</h1>
            <span class="text-body-secondary small">{{ tChoice('wishlist.found', items.total) }}</span>
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
                        <SearchSelect
                            id="type"
                            v-model="form.type"
                            :options="typeOptions"
                            :placeholder="t('catalog.any')"
                        />
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

                    <div class="col-12 col-lg-2 d-flex align-items-end order-last order-lg-0">
                        <button type="button" class="btn btn-outline-secondary w-100 text-nowrap" @click="reset">
                            {{ t('catalog.reset') }}
                        </button>
                    </div>

                    <div class="w-100"></div>
                    <div class="col-12 col-lg-4">
                        <SortControl v-model:by="form.sort" v-model:dir="form.dir" :options="sortOptions" />
                    </div>
                </div>
            </div>
        </div>

        <p v-if="!items.data.length" class="text-body-secondary">
            {{ t('wishlist.empty') }}
            <Link href="/catalog">{{ t('wishlist.empty_hint') }}</Link>
        </p>

        <div :class="masonry(cardSize)">
            <div v-for="item in items.data" :key="item.wish_id" class="card shadow-sm">
                <Link
                    :href="href(item)"
                    class="card-header d-flex align-items-center gap-2 text-truncate text-decoration-none"
                >
                    <span class="badge text-bg-secondary flex-shrink-0">{{ item.id }}</span>
                    <span class="text-truncate" :title="item.name">{{ item.name }}</span>
                </Link>

                <Link :href="href(item)">
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
                    <ColorDot v-if="item.color_id" :rgb="item.color_rgb" :name="item.color_name" />
                    <span v-if="item.theme" class="badge text-bg-light text-truncate" :title="item.theme">
                        {{ item.theme }}
                    </span>

                    <button
                        type="button"
                        class="btn btn-sm btn-link text-danger ms-auto p-0"
                        :title="t('wishlist.remove')"
                        :aria-label="t('wishlist.remove')"
                        @click="remove(item)"
                    >
                        <i class="mdi mdi-heart-remove-outline"></i>
                    </button>
                </div>
            </div>
        </div>

        <nav v-if="items.last_page > 1" class="mt-4">
            <ul class="pagination justify-content-center flex-wrap">
                <li
                    v-for="link in items.links"
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
