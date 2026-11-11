<script setup>
import axios from 'axios';
import { computed, ref } from 'vue';
import { url } from '@/support/base';
import { notify } from '@/support/toasts';
import { t, tChoice } from '@/i18n';

/**
 * Картинки, которые не удалось скачать.
 *
 * Служба повторяет их сама, по расписанию: сетевой сбой — назавтра, «нет
 * картинки» — через месяц, и всё реже с каждой неудачей. Кнопка ставит их на
 * ближайший прогон, не дожидаясь срока: например, когда BrickLink отвечал
 * плохо, а теперь отвечает. Качает их по-прежнему служба — здесь ждать нечего.
 */
const props = defineProps({
    /** { error, missing } — сколько не скачано и почему. */
    failures: { type: Object, required: true },
});

const counts = ref({ ...props.failures });
const busy = ref(false);
const total = computed(() => counts.value.error + counts.value.missing);

async function retry() {
    busy.value = true;

    try {
        const { data } = await axios.post(url('/settings/images/retry'));

        notify(data.message, 'success', 5000);
    } catch (error) {
        notify(error.response?.data?.message ?? t('errors.save_failed'));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="border-top pt-3 mt-3">
        <h3 class="h6">{{ t('settings.images') }}</h3>

        <p v-if="! total" class="text-body-secondary mb-0">{{ t('settings.images_all') }}</p>

        <template v-else>
            <p class="mb-2">
                {{ tChoice('settings.images_failed', total) }}
                <span class="text-body-secondary">
                    — {{ t('settings.images_reasons', { error: counts.error, missing: counts.missing }) }}
                </span>
            </p>

            <button type="button" class="btn btn-outline-primary" :disabled="busy" @click="retry">
                <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>
                <i v-else class="mdi mdi-image-refresh-outline"></i>
                {{ t('settings.images_retry') }}
            </button>

            <div class="form-text">{{ t('settings.images_hint') }}</div>
        </template>
    </div>
</template>
