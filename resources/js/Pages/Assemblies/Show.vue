<script setup>
import { url } from '@/support/base';
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import Link from '@/Components/AppLink.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import AssemblyImage from '@/Components/AssemblyImage.vue';
import OwnedLotsTable from '@/Components/OwnedLotsTable.vue';
import EntryMetaForm from '@/Components/EntryMetaForm.vue';
import EntryMetaBadges from '@/Components/EntryMetaBadges.vue';
import EntryMetaRows from '@/Components/EntryMetaRows.vue';
import EntryNote from '@/Components/EntryNote.vue';
import AssemblyPartsDialog from '@/Components/AssemblyPartsDialog.vue';
import AssemblyContentsDialog from '@/Components/AssemblyContentsDialog.vue';
import { patchField } from '@/support/save';
import { notify } from '@/support/toasts';
import { t, tChoice } from '@/i18n';

/**
 * One assembly: a group of loose parts with a name of its own.
 *
 * Parts come in from the loose pile and go back to it, so every change here is
 * a move, never a purchase and never a loss. The collection holds the same
 * bricks afterwards.
 */
const props = defineProps({
    entry: { type: Object, required: true },
    parts: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({}) },
    meta: { type: Object, required: true },
    dictionaries: { type: Object, required: true },
    currency: { type: String, default: 'RUB' },
});

const page = usePage();
const flash = computed(() => page.props.flash);

const meta = reactive({ ...props.meta });

function applyMeta({ meta: saved }) {
    Object.assign(meta, saved);
}

const name = ref(props.entry.name);
const savingName = ref(false);

watch(() => props.entry.name, (value) => (name.value = value));

async function saveName() {
    if (!name.value.trim() || name.value === props.entry.name) {
        return;
    }

    savingName.value = true;

    const result = await patchField(
        `/assemblies/${props.entry.id}`,
        { name: name.value.trim() },
        { onRevert: () => (name.value = props.entry.name) },
    );

    savingName.value = false;

    if (result) {
        notify(result.message ?? t('collection.saved'), 'success', 2000);
        router.reload({ only: ['entry'] });
    }
}

const cover = ref(null);

function uploadCover(event) {
    const file = event.target.files?.[0];

    if (!file) {
        return;
    }

    router.post(url(`/assemblies/${props.entry.id}/image`), { image: file }, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => (cover.value.value = ''),
    });
}

function removeCover() {
    router.delete(url(`/assemblies/${props.entry.id}/image`), { preserveScroll: true });
}

const addDialog = ref(null);
const editDialog = ref(null);

// A move changes the parts and the counts, and nothing else on the page.
function refresh() {
    router.reload({ only: ['parts', 'totals'], preserveScroll: true });
}

function remove() {
    if (window.confirm(t('assembly.remove_confirm', { name: props.entry.name }))) {
        router.delete(url(`/assemblies/${props.entry.id}`));
    }
}
</script>

<template>
    <Head :title="entry.name" />

    <AppLayout>
        <div v-if="flash?.message" class="alert alert-success d-flex align-items-center gap-2">
            <i class="mdi mdi-check-circle-outline"></i>
            <span>{{ flash.message }}</span>
        </div>

        <nav class="mb-3">
            <Link href="/assemblies" class="text-decoration-none">
                <i class="mdi mdi-arrow-left"></i> {{ t('assembly.title') }}
            </Link>
        </nav>

        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-header">{{ entry.name || t('assembly.untitled') }}</div>

                    <AssemblyImage
                        :id="entry.id"
                        :has-image="entry.has_image"
                        :alt="entry.name"
                        class="card-img-top p-3"
                    />

                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between gap-2">
                            <span class="text-body-secondary">{{ t('item.parts') }}</span>
                            <span>{{ totals.parts ?? 0 }}</span>
                        </li>

                        <EntryMetaRows :meta="meta" :dictionaries="dictionaries" :currency="currency" />
                    </ul>

                    <div class="card-footer d-flex flex-wrap gap-1 align-items-center">
                        <EntryMetaBadges :meta="meta" :dictionaries="dictionaries" />
                    </div>
                </div>

                <EntryNote :note="meta.note" />

                <div class="accordion mt-4">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button
                                class="accordion-button collapsed"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#assemblyManage"
                            >
                                <i class="mdi mdi-shape-outline me-2"></i>
                                {{ t('assembly.manage') }}
                            </button>
                        </h2>
                        <div id="assemblyManage" class="accordion-collapse collapse">
                            <div class="accordion-body d-flex flex-column gap-3">
                                <div>
                                    <label for="assemblyName" class="form-label">{{ t('assembly.name') }}</label>
                                    <div class="input-group">
                                        <input
                                            id="assemblyName"
                                            v-model="name"
                                            type="text"
                                            maxlength="120"
                                            class="form-control"
                                            @keyup.enter="saveName"
                                        />
                                        <button
                                            type="button"
                                            class="btn btn-primary"
                                            :disabled="savingName"
                                            @click="saveName"
                                        >
                                            {{ t('collection.save') }}
                                        </button>
                                    </div>
                                </div>

                                <div>
                                    <label for="assemblyCover" class="form-label">{{ t('assembly.cover') }}</label>
                                    <input
                                        id="assemblyCover"
                                        ref="cover"
                                        type="file"
                                        accept="image/*"
                                        class="form-control"
                                        @change="uploadCover"
                                    />
                                    <div class="form-text">{{ t('assembly.cover_hint') }}</div>
                                    <button
                                        v-if="entry.has_image"
                                        type="button"
                                        class="btn btn-sm btn-link px-0"
                                        @click="removeCover"
                                    >
                                        {{ t('assembly.cover_remove') }}
                                    </button>
                                </div>

                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-outline-primary" @click="addDialog.open()">
                                        <i class="mdi mdi-plus"></i> {{ t('assembly.add_parts') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        :disabled="!parts.length"
                                        @click="editDialog.open()"
                                    >
                                        <i class="mdi mdi-playlist-edit"></i> {{ t('assembly.edit_parts') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <EntryMetaForm
                        :entry-id="entry.id"
                        :endpoint="`/assemblies/${entry.id}`"
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
                                <button type="button" class="btn btn-outline-danger w-100" @click="remove">
                                    {{ t('collection.remove') }}
                                </button>
                                <div class="form-text">{{ t('assembly.remove_hint') }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="card shadow-sm">
                    <div class="card-header d-flex align-items-center gap-2">
                        <span>{{ t('item.parts') }}</span>
                        <span class="badge text-bg-secondary">{{ tChoice('assembly.parts', totals.parts ?? 0) }}</span>
                    </div>

                    <div v-if="parts.length" class="card-body p-0">
                        <OwnedLotsTable :lots="parts" @saved="refresh" />
                    </div>

                    <div v-else class="card-body">
                        <p class="text-body-secondary mb-1">{{ t('assembly.empty') }}</p>
                        <p class="text-body-secondary small mb-0">{{ t('assembly.empty_hint') }}</p>
                    </div>
                </div>
            </div>
        </div>

        <AssemblyPartsDialog ref="addDialog" :entry-id="entry.id" @changed="refresh" />
        <AssemblyContentsDialog ref="editDialog" :entry-id="entry.id" :parts="parts" @changed="refresh" />
    </AppLayout>
</template>
