<script setup>
import axios from 'axios';
import { url } from '@/support/base';
import { notify } from '@/support/toasts';
import { computed, reactive, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import Link from '@/Components/AppLink.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ItemImage from '@/Components/ItemImage.vue';
import { KIND_ORDER, kindLabel } from '@/support/itemKinds';
import SetPartsList from '@/Components/SetPartsList.vue';
import OwnedNode from '@/Components/OwnedNode.vue';
import EntryMetaForm from '@/Components/EntryMetaForm.vue';
import EntryStatusBadges from '@/Components/EntryStatusBadges.vue';
import EntryMetaBadges from '@/Components/EntryMetaBadges.vue';
import EntryMetaRows from '@/Components/EntryMetaRows.vue';
import EntryNote from '@/Components/EntryNote.vue';
import ExternalLinks from '@/Components/ExternalLinks.vue';
import ExportButton from '@/Components/ExportButton.vue';
import { exportItems } from '@/support/exports';
import { t } from '@/i18n';

const props = defineProps({
    links: { type: Array, default: () => [] },
    entry: { type: Object, required: true },
    contents: { type: Array, default: () => [] },
    meta: { type: Object, required: true },
    dictionaries: { type: Object, required: true },
    currency: { type: String, default: 'RUB' },
});

const page = usePage();
const flash = computed(() => page.props.flash);

// The two derived statuses are recalculated by the server on every save and
// come back with the response, so the page updates them without a re-render.
// The group counts do not need it: they are computed from the rows, and the
// row a field just saved updates itself.
const flags = reactive({
    flag_incomplete: props.entry.flag_incomplete,
    flag_missing_figs: props.entry.flag_missing_figs,
});

function applySaved(result) {
    Object.assign(flags, result.flags ?? {});
}

/**
 * Ticking "box" in the management block has to show up on the card without a
 * reload, so the codes are local state the form updates too.
 */
const statusCodes = ref([...(props.entry.status_codes ?? [])]);

// Карточка показывает то же, что правится в «Управлении», поэтому держит
// собственную копию: сохранение обновляет её, а страница не перерисовывается.
const meta = reactive({ ...props.meta });

function applyMeta({ codes, meta: saved }) {
    statusCodes.value = codes;
    Object.assign(meta, saved);
}

/**
 * Top level of the copy, grouped by kind: subsets, minifigures, parts.
 *
 * The count is what the group holds directly, so it always matches the list
 * underneath it. Parts inside a minifigure are counted by the minifigure's
 * group, which is where they are shown.
 *
 * Losses and spares are summed over the whole subtree instead. A part missing
 * from a minifigure is exactly what someone wants to see without opening
 * every accordion to find it, and a header that stayed silent about it would
 * be misleading in the one case that matters.
 */
const groups = computed(() => {
    const walk = (lots, pick) =>
        lots.reduce((sum, lot) => sum + pick(lot) + walk(lot.children ?? [], pick), 0);

    return KIND_ORDER
        .map((type) => {
            const lots = props.contents.filter((lot) => lot.type === type);

            return {
                key: type,
                label: kindLabel(type),
                lots,
                count: lots.filter((lot) => lot.counts).reduce((sum, lot) => sum + lot.qty, 0),
                // Spares and alternates hang off the copy without being part
                // of it, so they are counted apart from the total.
                extras: walk(lots, (lot) => (lot.is_extra ? lot.qty : 0)),
                lost: walk(lots, (lot) => (lot.counts ? lot.lost_qty : 0)),
                // Есть ли что выгружать: тот же отбор, что у сервера, — парная
                // строка в коробке лежит, и её недостачу считают тоже.
                short: walk(lots, (lot) => (lot.counts || lot.is_counterpart ? lot.lost_qty : 0)) > 0,
                shortage: exportItems(`/sets/${props.entry.id}/shortage/${type}`, ['wanted', 'inventory', 'bsx']),
            };
        })
        .filter((group) => group.lots.length > 0);
});

function remove() {
    if (window.confirm(t('collection.remove_confirm', { name: props.entry.name }))) {
        router.delete(url(`/sets/${props.entry.id}`));
    }
}

/*
 * Расформировать: детали и фигурки — в свободные.
 *
 * Сначала сервер говорит, что получится, — сколько деталей в сколько партий,
 * сколько фигурок, что потеряно, — и только с этим текстом перед глазами
 * человек соглашается. Обратного действия нет: набор после этого удалён.
 */
const partingOut = ref(false);

async function partOut() {
    partingOut.value = true;

    try {
        const { data } = await axios.get(url(`/sets/${props.entry.id}/partout`));

        if (! window.confirm(data.message)) {
            partingOut.value = false;

            return;
        }

        router.post(url(`/sets/${props.entry.id}/partout`), {}, {
            onFinish: () => (partingOut.value = false),
        });
    } catch (error) {
        partingOut.value = false;
        notify(error.response?.data?.message ?? t('errors.save_failed'));
    }
}
</script>

<template>
    <Head :title="`${entry.item_id} ${entry.name}`" />

    <AppLayout>
        <div v-if="flash?.message" class="alert alert-success d-flex align-items-center gap-2">
            <i class="mdi mdi-check-circle-outline"></i>
            <span>{{ flash.message }}</span>
        </div>

        <nav class="mb-3">
            <Link href="/sets" class="text-decoration-none">
                <i class="mdi mdi-arrow-left"></i> {{ t('nav.sets') }}
            </Link>
        </nav>

        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-start gap-2">
                        <span class="badge text-bg-secondary flex-shrink-0 mt-1">{{ entry.item_id }}</span>
                        <!-- A detail page has room; the name is not cut here. -->
                        <span>{{ entry.name }}</span>
                    </div>

                    <ItemImage
                        :type="entry.type"
                        :id="entry.item_id"
                        :color-id="entry.image_color_id"
                        :alt="entry.name"
                        class="card-img-top p-3"
                        zoom
                    />

                    <ul class="list-group list-group-flush">
                        <li v-if="entry.year" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('catalog.year') }}</span>
                            <span>{{ entry.year }}</span>
                        </li>
                        <li v-if="entry.theme" class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('catalog.theme') }}</span>
                            <span class="text-end">{{ entry.theme }}</span>
                        </li>

                        <EntryMetaRows :meta="meta" :dictionaries="dictionaries" :currency="currency" />
                    </ul>

                    <div class="card-footer d-flex flex-wrap gap-1 align-items-center">
                        <EntryStatusBadges
                            :status-codes="statusCodes"
                            :incomplete="flags.flag_incomplete"
                            :missing-figs="flags.flag_missing_figs"
                        />
                        <EntryMetaBadges :meta="meta" :dictionaries="dictionaries" />
                        <!-- В том же цвете, в каком экземпляр лежит на полке:
                             у аксессуара цвет бывает свой. -->
                        <Link
                            :href="`/catalog/${entry.type}/${encodeURIComponent(entry.item_id)}`
                                + (entry.color_id === null ? '' : `?color=${entry.color_id}`)"
                            class="btn btn-sm btn-link ms-auto p-0"
                        >
                            {{ t('collection.open_in_catalog') }}
                        </Link>
                    </div>
                </div>

                <ExternalLinks :links="links" />

                <EntryNote :note="meta.note" />

                <div class="accordion mt-4">
                    <EntryMetaForm
                        :entry-id="entry.id"
                        :meta="props.meta"
                        :dictionaries="dictionaries"
                        :currency="currency"
                        @saved="applyMeta"
                    />

                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button
                                class="accordion-button collapsed text-danger"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#dangerZone"
                            >
                                <i class="mdi mdi-alert-outline me-2"></i>
                                {{ t('collection.danger_zone') }}
                            </button>
                        </h2>
                        <div id="dangerZone" class="accordion-collapse collapse">
                            <div class="accordion-body">
                                <!-- Только у набора: у снаряжения и книги
                                     раскладывать нечего. -->
                                <template v-if="entry.type === 'S'">
                                    <button
                                        type="button"
                                        class="btn btn-outline-warning w-100"
                                        :disabled="partingOut"
                                        @click="partOut"
                                    >
                                        <span v-if="partingOut" class="spinner-border spinner-border-sm me-1"></span>
                                        {{ t('collection.partout') }}
                                    </button>
                                    <div class="form-text mb-3">{{ t('collection.partout_hint') }}</div>
                                </template>

                                <button type="button" class="btn btn-outline-danger w-100" @click="remove">
                                    {{ t('collection.remove') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <!--
                    Everything the copy consists of, grouped by kind. The counts
                    that used to sit in a separate header live in these headers
                    instead: the number belongs next to what it counts, and a
                    long parts table no longer pushes it off the screen.
                -->
                <div class="accordion">
                    <div v-for="group in groups" :key="group.key" class="accordion-item">
                        <h2 class="accordion-header">
                            <button
                                class="accordion-button collapsed gap-2"
                                type="button"
                                data-bs-toggle="collapse"
                                :data-bs-target="`#group-${group.key}`"
                            >
                                <span>{{ t(group.label) }}</span>
                                <span class="badge text-bg-secondary">{{ group.count }}</span>
                                <!-- На узком экране от бейджа остаётся число:
                                     подпись к нему вчетверо длиннее самого
                                     числа, а значок и цвет и так говорят, о чём
                                     речь. -->
                                <span v-if="group.extras" class="badge text-bg-light border">
                                    <span class="hide-narrow">{{ t('item.extras') }}: </span>{{ group.extras }}
                                </span>
                                <span v-if="group.lost" class="badge text-bg-warning ms-auto">
                                    <i class="mdi mdi-alert-outline"></i>
                                    <span class="hide-narrow">{{ t('collection.lost') }}: </span>{{ group.lost }}
                                </span>
                            </button>
                        </h2>

                        <div :id="`group-${group.key}`" class="accordion-collapse collapse">
                            <div class="accordion-body" :class="{ 'p-0': group.key === 'P' }">
                                <!-- Над списком, а не в заголовке: заголовок
                                     группы — это сама кнопка аккордеона, и
                                     вложить в неё меню нельзя. Выгружается всё
                                     поддерево группы, сколь угодно глубокое. -->
                                <div v-if="group.short && group.key !== 'P'" class="d-flex justify-content-end mb-3">
                                    <ExportButton :items="group.shortage" :label="t('collection.export_shortage')" />
                                </div>

                                <!-- У деталей выгрузка стоит в одной строке с отбором и
                                     порядком: это один и тот же вопрос о недостаче. -->
                                <SetPartsList
                                    v-if="group.key === 'P'"
                                    :lots="contents"
                                    :shortage="group.short ? group.shortage : null"
                                    @saved="applySaved"
                                />

                                <div v-else class="accordion">
                                    <OwnedNode
                                        v-for="(lot, index) in group.lots"
                                        :key="lot.id"
                                        :lot="lot"
                                        :dom-id="`owned-${group.key}-${index}`"
                                        @saved="applySaved"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
