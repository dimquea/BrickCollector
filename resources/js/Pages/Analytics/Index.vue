<script setup>
import { Head } from '@inertiajs/vue3';
import Link from '@/Components/AppLink.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { locale, t } from '@/i18n';

/**
 * Сводка по коллекции.
 *
 * Четыре аккордеона в два столбца на широком экране и в один на телефоне.
 * Числа — не таблицей: подпись под крупной цифрой читается с одного взгляда,
 * а таблица из четырёх строк заставляет читать её как таблицу.
 *
 * Числа в разрезах кликабельны и ведут в раздел с тем же фильтром, по которому
 * они посчитаны, — иначе сводка остаётся сводкой, из которой некуда пойти.
 */
const props = defineProps({
    counts: { type: Object, required: true },
    finance: { type: Object, required: true },
    themes: { type: Array, default: () => [] },
    years: { type: Array, default: () => [] },
    currency: { type: String, default: 'RUB' },
});

const number = (value) => new Intl.NumberFormat(locale.value).format(value ?? 0);

const money = (minor) =>
    minor === null || minor === undefined
        ? '—'
        : new Intl.NumberFormat(locale.value, { style: 'currency', currency: props.currency })
              .format(minor / 100);

/** Какие числа показывать в блоке и в каком порядке. */
const groups = [
    { key: 'sets', fields: ['total', 'unique', 'incomplete'] },
    { key: 'minifigures', fields: ['total', 'in_sets', 'loose', 'unique', 'incomplete'] },
    { key: 'parts', fields: ['total', 'in_sets', 'loose', 'assemblies', 'unique', 'lost', 'missing_assemblies'] },
];

/*
 * Деньги по видам. Снаряжение и сборки отдельного блока не получают — их
 * единицы, — но входят в «Всего». У свободных деталей только сумма: партия
 * бывает и в одну деталь, и в двести, и средняя по партиям ничего не скажет.
 */
const spending = [
    { key: 'sets', title: 'analytics.money_sets', data: props.finance.sets, href: (id) => `/sets/${id}` },
    { key: 'figures', title: 'analytics.money_figures', data: props.finance.figures, href: (id) => `/minifigures/copy/${id}` },
    { key: 'parts', title: 'analytics.money_parts', data: props.finance.parts, href: null },
];
</script>

<template>
    <Head :title="t('nav.analytics')" />

    <AppLayout>
        <h1 class="h4 mb-3">{{ t('nav.analytics') }}</h1>

        <div class="row g-4">
            <div class="col-12 col-lg-6">
                <div class="accordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button
                                class="accordion-button"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#analyticsCounts"
                            >
                                <i class="mdi mdi-counter me-2"></i>
                                {{ t('analytics.counts') }}
                            </button>
                        </h2>
                        <div id="analyticsCounts" class="accordion-collapse collapse show">
                            <div class="accordion-body">
                                <div v-for="group in groups" :key="group.key" class="mb-4 last-child-mb-0">
                                    <h3 class="h6">{{ t(`analytics.group_${group.key}`) }}</h3>

                                    <div class="d-flex flex-wrap gap-4">
                                        <div v-for="field in group.fields" :key="field">
                                            <div class="fs-4 fw-semibold">
                                                {{ number(counts[group.key][field]) }}
                                            </div>
                                            <div class="text-body-secondary small">
                                                {{ t(`analytics.field_${field}`) }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="accordion mt-4">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button
                                class="accordion-button"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#analyticsThemes"
                            >
                                <i class="mdi mdi-shape-outline me-2"></i>
                                {{ t('analytics.by_theme') }}
                            </button>
                        </h2>
                        <div id="analyticsThemes" class="accordion-collapse collapse show">
                            <div class="accordion-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <!-- У минифигурок два числа: по
                                                 артикулам кликают, а на полке
                                                 стоят экземпляры. Одним числом
                                                 под общим заголовком это
                                                 читалось как «столько у меня
                                                 фигурок», что неправда. -->
                                            <tr>
                                                <th class="text-nowrap" rowspan="2">{{ t('catalog.theme') }}</th>
                                                <th class="text-nowrap text-end" rowspan="2">{{ t('nav.sets') }}</th>
                                                <th class="text-nowrap text-center" colspan="2">
                                                    {{ t('nav.minifigures') }}
                                                </th>
                                                <th class="text-nowrap text-end" rowspan="2">{{ t('analytics.price') }}</th>
                                            </tr>
                                            <tr>
                                                <th class="text-nowrap text-end fw-normal small">
                                                    {{ t('analytics.field_unique') }}
                                                </th>
                                                <th class="text-nowrap text-end fw-normal small">
                                                    {{ t('analytics.field_total') }}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="row in themes" :key="row.label">
                                                <td>{{ row.label }}</td>
                                                <td class="text-end">
                                                    <Link
                                                        v-if="row.key"
                                                        :href="`/sets?theme_id=${row.key}`"
                                                        class="text-decoration-none"
                                                    >{{ number(row.sets) }}</Link>
                                                    <span v-else>{{ number(row.sets) }}</span>
                                                </td>
                                                <td class="text-end">
                                                    <Link
                                                        v-if="row.key && row.figures"
                                                        :href="`/minifigures?theme_id=${row.key}`"
                                                        class="text-decoration-none"
                                                    >{{ number(row.figures) }}</Link>
                                                    <span v-else class="text-body-secondary">
                                                        {{ number(row.figures) }}
                                                    </span>
                                                </td>
                                                <td
                                                    class="text-end"
                                                    :class="{ 'text-body-secondary': ! row.copies }"
                                                >{{ number(row.copies) }}</td>
                                                <td class="text-end text-nowrap">{{ money(row.price) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-6">
                <div class="accordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button
                                class="accordion-button"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#analyticsFinance"
                            >
                                <i class="mdi mdi-cash-multiple me-2"></i>
                                {{ t('analytics.finance') }}
                            </button>
                        </h2>
                        <div id="analyticsFinance" class="accordion-collapse collapse show">
                            <div class="accordion-body">
                                <!-- Всего — все затраты на коллекцию, какого бы вида ни
                                     были покупки. Средняя и самая дорогая — только
                                     внутри вида: набор и партия в одной средней не
                                     значат ничего. Сложено так же, как «Что в
                                     коллекции»: заголовок группы и ряд чисел. -->
                                <div class="mb-4">
                                    <div class="fs-4 fw-semibold">{{ money(finance.total) }}</div>
                                    <div class="text-body-secondary small">{{ t('analytics.spent') }}</div>
                                </div>

                                <p v-if="! finance.total" class="text-body-secondary mb-0">
                                    {{ t('analytics.no_prices') }}
                                </p>

                                <template v-else>
                                    <div v-for="block in spending" :key="block.key" class="mb-4 last-child-mb-0">
                                        <h3 class="h6">{{ t(block.title) }}</h3>

                                        <div class="d-flex flex-wrap gap-4">
                                            <div>
                                                <div class="fs-4 fw-semibold">{{ money(block.data.total) }}</div>
                                                <div class="text-body-secondary small">{{ t('analytics.spent') }}</div>
                                            </div>
                                            <template v-if="'priced' in block.data">
                                                <div>
                                                    <div class="fs-4 fw-semibold">
                                                        {{ t('analytics.priced_of', {
                                                            priced: number(block.data.priced),
                                                            entries: number(block.data.entries),
                                                        }) }}
                                                    </div>
                                                    <div class="text-body-secondary small">{{ t('analytics.priced') }}</div>
                                                </div>
                                                <div>
                                                    <div class="fs-4 fw-semibold">{{ money(block.data.average) }}</div>
                                                    <div class="text-body-secondary small">{{ t('analytics.average') }}</div>
                                                </div>
                                            </template>
                                        </div>

                                        <div v-if="block.data.priciest" class="mt-2">
                                            <div class="text-body-secondary small">{{ t('analytics.priciest') }}</div>
                                            <Link :href="block.href(block.data.priciest.entry_id)" class="text-decoration-none">
                                                <span class="badge text-bg-light border me-1">
                                                    {{ block.data.priciest.item_id }}
                                                </span>
                                                {{ block.data.priciest.name }}
                                            </Link>
                                            <span class="fw-semibold ms-1">{{ money(block.data.priciest.price) }}</span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="accordion mt-4">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button
                                class="accordion-button"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#analyticsYears"
                            >
                                <i class="mdi mdi-calendar-range-outline me-2"></i>
                                {{ t('analytics.by_year') }}
                            </button>
                        </h2>
                        <div id="analyticsYears" class="accordion-collapse collapse show">
                            <div class="accordion-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th class="text-nowrap" rowspan="2">{{ t('catalog.year') }}</th>
                                                <th class="text-nowrap text-end" rowspan="2">{{ t('nav.sets') }}</th>
                                                <th class="text-nowrap text-center" colspan="2">
                                                    {{ t('nav.minifigures') }}
                                                </th>
                                                <th class="text-nowrap text-end" rowspan="2">{{ t('analytics.price') }}</th>
                                            </tr>
                                            <tr>
                                                <th class="text-nowrap text-end fw-normal small">
                                                    {{ t('analytics.field_unique') }}
                                                </th>
                                                <th class="text-nowrap text-end fw-normal small">
                                                    {{ t('analytics.field_total') }}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="row in years" :key="row.key">
                                                <td>{{ row.label }}</td>
                                                <td class="text-end">
                                                    <Link
                                                        :href="`/sets?year=${row.key}`"
                                                        class="text-decoration-none"
                                                    >{{ number(row.sets) }}</Link>
                                                </td>
                                                <td class="text-end">
                                                    <Link
                                                        v-if="row.figures"
                                                        :href="`/minifigures?year=${row.key}`"
                                                        class="text-decoration-none"
                                                    >{{ number(row.figures) }}</Link>
                                                    <span v-else class="text-body-secondary">0</span>
                                                </td>
                                                <td
                                                    class="text-end"
                                                    :class="{ 'text-body-secondary': ! row.copies }"
                                                >{{ number(row.copies) }}</td>
                                                <td class="text-end text-nowrap">{{ money(row.price) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
