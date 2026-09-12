<script setup>
import { url } from '@/support/base';
import { reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Link from '@/Components/AppLink.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import AssemblyImage from '@/Components/AssemblyImage.vue';
import { debounce } from '@/support/debounce';
import { masonry } from '@/support/cards';
import { t, tChoice } from '@/i18n';

const props = defineProps({
    cardSize: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    assemblies: { type: Object, required: true },
});

const form = reactive({ q: props.filters.q ?? '', missing: Boolean(props.filters.missing) });

function submit() {
    const query = {};

    if (form.q) {
        query.q = form.q;
    }

    if (form.missing) {
        query.missing = true;
    }

    router.get(url('/assemblies'), query, { preserveState: true, preserveScroll: true, replace: true });
}

watch(() => form.q, debounce(submit, 300));
watch(() => form.missing, submit);

const name = ref('');
const creating = ref(false);

function create() {
    if (!name.value.trim()) {
        return;
    }

    creating.value = true;
    router.post(url('/assemblies'), { name: name.value.trim() }, {
        onFinish: () => (creating.value = false),
    });
}
</script>

<template>
    <Head :title="t('assembly.title')" />

    <AppLayout>
        <div class="d-flex flex-wrap align-items-baseline gap-2 mb-3">
            <h1 class="h4 mb-0">{{ t('assembly.title') }}</h1>
            <span class="text-body-secondary small">{{ tChoice('assembly.found', assemblies.total) }}</span>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <label for="q" class="form-label">{{ t('catalog.query') }}</label>
                        <input id="q" v-model="form.q" type="search" class="form-control" />
                        <div class="form-check mt-2">
                            <input id="missing" v-model="form.missing" class="form-check-input" type="checkbox" />
                            <label class="form-check-label" for="missing">{{ t('assembly.missing') }}</label>
                        </div>
                    </div>

                    <div class="col-12 col-lg-6">
                        <label for="newAssembly" class="form-label">{{ t('assembly.new') }}</label>
                        <form class="input-group" @submit.prevent="create">
                            <input
                                id="newAssembly"
                                v-model="name"
                                type="text"
                                maxlength="120"
                                class="form-control"
                                :placeholder="t('assembly.name')"
                            />
                            <button type="submit" class="btn btn-primary" :disabled="creating">
                                <i class="mdi mdi-plus"></i> {{ t('assembly.new') }}
                            </button>
                        </form>
                        <div class="form-text">{{ t('assembly.new_hint') }}</div>
                    </div>
                </div>
            </div>
        </div>

        <p v-if="!assemblies.data.length" class="text-body-secondary">
            {{ t('collection.empty') }}
        </p>

        <div :class="masonry(cardSize)">
            <div v-for="assembly in assemblies.data" :key="assembly.id" class="card shadow-sm">
                <div class="card-header text-truncate" :title="assembly.name">
                    {{ assembly.name || t('assembly.untitled') }}
                </div>

                <Link :href="`/assemblies/${assembly.id}`">
                    <AssemblyImage :id="assembly.id" :has-image="assembly.has_image" :alt="assembly.name" />
                </Link>

                <div class="card-footer d-flex flex-wrap gap-1 align-items-center">
                    <span class="badge text-bg-success">{{ tChoice('assembly.parts', assembly.parts) }}</span>
                    <span
                        v-if="assembly.missing"
                        class="badge text-bg-warning"
                        :title="t('assembly.missing_hint')"
                    >
                        <i class="mdi mdi-alert-outline"></i> {{ assembly.missing }}
                    </span>
                    <span
                        v-for="tag in assembly.tags"
                        :key="tag.name"
                        class="badge"
                        :class="`text-bg-${tag.color}`"
                    >
                        {{ tag.name }}
                    </span>
                </div>
            </div>
        </div>

        <nav v-if="assemblies.last_page > 1" class="mt-4">
            <ul class="pagination justify-content-center flex-wrap">
                <li
                    v-for="link in assemblies.links"
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
