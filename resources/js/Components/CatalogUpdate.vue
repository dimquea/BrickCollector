<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import axios from 'axios';
import { url } from '@/support/base';
import { notify } from '@/support/toasts';
import { locale, t } from '@/i18n';

/**
 * Обновление справочника.
 *
 * Кнопка только запускает работу: идёт она отдельным процессом и переживает
 * уход со страницы. Пока идёт — спрашиваем раз в три секунды, на каком шаге.
 * Шаг заодно служит признаком жизни: процесс, убитый на середине, перестаёт
 * его обновлять, и сервер сам сообщит, что обновление брошено.
 */
const props = defineProps({
    catalog: { type: Object, required: true },
});

const state = ref({ ...props.catalog });
const starting = ref(false);
let timer = null;

const running = computed(() => state.value.status?.state === 'running');
const failed = computed(() => state.value.status?.state === 'failed');

const importedAt = computed(() =>
    state.value.imported_at
        ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' })
              .format(new Date(state.value.imported_at.replace(' ', 'T')))
        : t('settings.catalog_never'),
);

async function poll() {
    try {
        const { data } = await axios.get(url('/settings/catalog'));
        state.value = data;
    } catch {
        // Молча: сеть моргнула — спросим через три секунды снова.
        return;
    }

    if (!running.value) {
        stop();
    }
}

function watchProgress() {
    timer ??= setInterval(poll, 3000);
}

function stop() {
    clearInterval(timer);
    timer = null;
}

onBeforeUnmount(stop);

if (running.value) {
    watchProgress();
}

async function refresh() {
    starting.value = true;

    try {
        const { data } = await axios.post(url('/settings/catalog'));
        state.value = data;
        notify(data.message, 'success', 3000);
        watchProgress();
    } catch (error) {
        notify(error.response?.data?.message ?? t('errors.save_failed'));
    } finally {
        starting.value = false;
    }
}
</script>

<template>
    <p class="mb-1">
        {{ t('settings.catalog_items') }}:
        <strong>{{ (state.items ?? 0).toLocaleString(locale) }}</strong>
    </p>

    <p class="mb-3">
        {{ t('settings.catalog_imported_at') }}: <strong>{{ importedAt }}</strong>
    </p>

    <div v-if="running" class="alert alert-info d-flex align-items-center gap-2">
        <span class="spinner-border spinner-border-sm flex-shrink-0"></span>
        <span>
            {{ t('settings.catalog_updating') }}<template v-if="state.status.step">: {{ state.status.step }}</template>
        </span>
    </div>

    <div v-else-if="failed" class="alert alert-warning d-flex gap-2">
        <i class="mdi mdi-alert-outline flex-shrink-0"></i>
        <span>
            <strong>{{ t('settings.catalog_failed') }}.</strong>
            {{ state.status.message }}
        </span>
    </div>

    <button
        type="button"
        class="btn btn-primary"
        :disabled="running || starting"
        @click="refresh"
    >
        <i class="mdi mdi-database-sync-outline me-1"></i>
        {{ t('settings.catalog_update') }}
    </button>

    <p class="text-body-secondary small mt-2 mb-0">{{ t('settings.catalog_update_hint') }}</p>
</template>
