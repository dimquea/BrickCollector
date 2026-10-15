<script setup>
import axios from 'axios';
import { ref } from 'vue';
import { url } from '@/support/base';
import { notify } from '@/support/toasts';
import { t } from '@/i18n';

/**
 * Пересобрать опись экземпляров по нынешнему справочнику.
 *
 * Рядом с обновлением самого справочника, потому что следует за ним: сперва
 * привозят новые данные, потом решают, применять ли их к своим коробкам.
 * Отдельной кнопкой, а не автоматически: справочник правят каждый день, а что
 * лежит на полке, знает только владелец.
 *
 * Ответа ждём на месте — работа занимает секунды, а не минуты, как импорт
 * справочника, и заводить ради неё отдельный процесс со своим файлом
 * состояния было бы больше машинерии, чем дела.
 */
const running = ref(false);
const result = ref(null);
const orphaned = ref(null);

async function refresh() {
    running.value = true;
    result.value = null;
    orphaned.value = null;

    try {
        const { data } = await axios.post(url('/settings/inventories'));

        result.value = data.message;
        orphaned.value = data.orphaned;
        notify(data.message, 'success', 4000);
    } catch (error) {
        notify(error.response?.data?.message ?? t('errors.save_failed'));
    } finally {
        running.value = false;
    }
}
</script>

<template>
    <div class="border-top pt-3 mt-3">
        <h3 class="h6">{{ t('settings.inventories') }}</h3>

        <button type="button" class="btn btn-outline-primary" :disabled="running" @click="refresh">
            <span v-if="running" class="spinner-border spinner-border-sm me-1"></span>
            <i v-else class="mdi mdi-package-variant-closed"></i>
            {{ t('settings.inventories_update') }}
        </button>

        <div class="form-text">{{ t('settings.inventories_hint') }}</div>

        <p v-if="result" class="alert alert-info mt-3 mb-0">{{ result }}</p>
        <p v-if="orphaned" class="alert alert-warning mt-3 mb-0">{{ orphaned }}</p>
    </div>
</template>
