<script setup>
import axios from 'axios';
import { ref } from 'vue';
import Link from '@/Components/AppLink.vue';
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
const orphans = ref([]);

async function refresh() {
    running.value = true;
    result.value = null;
    orphaned.value = null;
    orphans.value = [];

    try {
        const { data } = await axios.post(url('/settings/inventories'));

        result.value = data.message;
        orphaned.value = data.orphaned;
        orphans.value = data.orphans ?? [];
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
        <div v-if="orphaned" class="alert alert-warning mt-3 mb-0">
            <p class="mb-2">{{ orphaned }}</p>

            <!-- Поимённо: из какого экземпляра, какая позиция и сколько.
                 Иначе остаётся только гадать, что именно пропало. -->
            <ul class="mb-0 ps-3">
                <li v-for="(orphan, index) in orphans" :key="index">
                    <Link :href="`/sets/${orphan.entry_id}`" class="text-decoration-none">
                        {{ orphan.entry }}
                    </Link>
                    · {{ orphan.name }}
                    <span class="badge text-bg-light border">{{ orphan.item_id }}</span>
                    <span v-if="orphan.color">· {{ orphan.color }}</span>
                    · {{ orphan.lost }}
                </li>
            </ul>
        </div>
    </div>
</template>
