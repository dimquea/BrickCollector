<script setup>
import { computed } from 'vue';
import Link from '@/Components/AppLink.vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import { locale, t } from '@/i18n';

/**
 * Поля меты: что человек знает об экземпляре.
 *
 * Только поля и ничего больше — ни сохранения, ни адреса, куда сохранять. У них
 * два потребителя с разными привычками: страница экземпляра пишет каждую правку
 * сразу, а импорт собирает значения и не пишет ничего, пока не нажмут «добавить
 * в коллекцию». Общей здесь должна быть разметка, а не поведение, иначе у
 * второго потребителя заведётся своя, слегка другая копия тех же контролов, и
 * они разъедутся при первой же правке.
 *
 * Идентификаторы строятся от префикса: на странице импорта таких наборов полей
 * бывает раскрыто несколько разом, и «id=note» в каждом заставил бы подписи
 * указывать не на своё поле.
 *
 * Статусы показываются не всегда: у детали нет ни коробки, ни инструкции, а при
 * импорте их не спрашивают вовсе. Решает это состав словарей, а не отдельный
 * признак — пустой словарь и значит «здесь не о чем спрашивать».
 */
const props = defineProps({
    form: { type: Object, required: true },
    dictionaries: { type: Object, required: true },
    currency: { type: String, default: 'RUB' },
    prefix: { type: String, default: 'meta' },
});

const emit = defineEmits(['changed']);

const field = (name) => `${props.prefix}-${name}`;

const priceHint = computed(() =>
    props.form.price === ''
        ? ''
        : new Intl.NumberFormat(locale.value, { style: 'currency', currency: props.currency })
              .format(Number(props.form.price) || 0),
);

const hasDictionary = (name) => (props.dictionaries[name] ?? []).length > 0;
</script>

<template>
    <div class="row g-3">
        <div class="col-12 col-sm-6">
            <label :for="field('acquired')" class="form-label">{{ t('collection.acquired_at') }}</label>
            <input
                :id="field('acquired')"
                v-model="form.acquired_at"
                type="date"
                class="form-control"
                @change="emit('changed')"
            />
        </div>

        <div class="col-12 col-sm-6">
            <label :for="field('price')" class="form-label">{{ t('collection.price') }}</label>
            <input
                :id="field('price')"
                v-model="form.price"
                type="number"
                step="0.01"
                min="0"
                class="form-control"
                @change="emit('changed')"
            />
            <div v-if="priceHint" class="form-text">{{ priceHint }}</div>
        </div>

        <div class="col-12 col-sm-6">
            <label :for="field('source')" class="form-label">{{ t('collection.source') }}</label>
            <SearchSelect
                v-if="hasDictionary('sources')"
                :id="field('source')"
                v-model="form.source_id"
                :options="dictionaries.sources.map((s) => ({ value: s.id, label: s.name }))"
                :placeholder="t('catalog.any')"
                @update:model-value="emit('changed')"
            />
            <p v-else class="form-text mb-0">
                {{ t('collection.dictionary_empty') }}
                <Link href="/settings">{{ t('nav.settings') }}</Link>
            </p>
        </div>

        <div class="col-12 col-sm-6">
            <label :for="field('storage')" class="form-label">{{ t('collection.storage') }}</label>
            <SearchSelect
                v-if="hasDictionary('storages')"
                :id="field('storage')"
                v-model="form.storage_id"
                :options="dictionaries.storages.map((s) => ({ value: s.id, label: s.name }))"
                :placeholder="t('catalog.any')"
                @update:model-value="emit('changed')"
            />
            <p v-else class="form-text mb-0">
                {{ t('collection.dictionary_empty') }}
                <Link href="/settings">{{ t('nav.settings') }}</Link>
            </p>
        </div>

        <div v-if="hasDictionary('statuses')" class="col-12">
            <span class="form-label d-block">{{ t('collection.statuses') }}</span>
            <div class="d-flex flex-wrap gap-3">
                <div v-for="status in dictionaries.statuses" :key="status.id" class="form-check">
                    <input
                        :id="field(`status-${status.id}`)"
                        v-model="form.status_ids"
                        class="form-check-input"
                        type="checkbox"
                        :value="status.id"
                        @change="emit('changed')"
                    />
                    <label class="form-check-label" :for="field(`status-${status.id}`)">
                        {{ status.name }}
                    </label>
                </div>
            </div>
        </div>

        <div class="col-12">
            <span class="form-label d-block">{{ t('collection.tags') }}</span>
            <div v-if="hasDictionary('tags')" class="d-flex flex-wrap gap-3">
                <div v-for="tag in dictionaries.tags" :key="tag.id" class="form-check">
                    <input
                        :id="field(`tag-${tag.id}`)"
                        v-model="form.tag_ids"
                        class="form-check-input"
                        type="checkbox"
                        :value="tag.id"
                        @change="emit('changed')"
                    />
                    <label class="form-check-label" :for="field(`tag-${tag.id}`)">
                        <span class="badge" :class="`text-bg-${tag.color}`">{{ tag.name }}</span>
                    </label>
                </div>
            </div>
            <p v-else class="form-text mb-0">
                {{ t('collection.dictionary_empty') }}
                <Link href="/settings">{{ t('nav.settings') }}</Link>
            </p>
        </div>

        <div class="col-12">
            <label :for="field('note')" class="form-label">{{ t('collection.note') }}</label>
            <textarea
                :id="field('note')"
                v-model="form.note"
                class="form-control"
                rows="3"
                @change="emit('changed')"
            ></textarea>
        </div>
    </div>
</template>
