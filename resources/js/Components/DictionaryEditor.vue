<script setup>
import { url } from '@/support/base';
import { reactive, ref } from 'vue';
import axios from 'axios';
import { notify } from '@/support/toasts';
import { t } from '@/i18n';

/**
 * Inline editor for one internal dictionary.
 *
 * Rows are edited in place and saved on blur, like everything else that writes
 * a single field: the page never reloads, so the rest of the settings form
 * keeps its state.
 */
const props = defineProps({
    kind: { type: String, required: true },
    rows: { type: Array, required: true },
    colors: { type: Array, default: () => [] },
    hasColor: { type: Boolean, default: false },
    hasVisibility: { type: Boolean, default: false },
    hasActive: { type: Boolean, default: false },
});

const items = reactive([...props.rows]);
const draft = reactive({ name: '', color: 'secondary', show_in_list: false, is_active: true });
const busy = ref(null);

function payload(row) {
    const data = { name: row.name };

    if (props.hasColor) {
        data.color = row.color;
        data.show_in_list = row.show_in_list;
    }

    if (props.hasActive) {
        data.is_active = row.is_active;
    }

    return data;
}

function fail(error) {
    const data = error.response?.data;

    notify(
        data?.errors ? Object.values(data.errors).flat().join(' ') : (data?.message ?? t('errors.save_failed')),
    );
}

async function add() {
    if (!draft.name.trim()) {
        return;
    }

    busy.value = 'new';

    try {
        const { data } = await axios.post(url(`/settings/dictionary/${props.kind}`), payload(draft));
        items.push(data.row);
        Object.assign(draft, { name: '', color: 'secondary', show_in_list: false, is_active: true });
    } catch (error) {
        fail(error);
    } finally {
        busy.value = null;
    }
}

async function save(row, index) {
    const previous = { ...items[index] };
    busy.value = row.id;

    try {
        const { data } = await axios.patch(url(`/settings/dictionary/${props.kind}/${row.id}`), payload(row));
        Object.assign(items[index], data.row);
    } catch (error) {
        // Put the row back the way the server has it.
        Object.assign(items[index], previous);
        fail(error);
    } finally {
        busy.value = null;
    }
}

async function remove(row, index) {
    if (!window.confirm(t('dictionaries.remove_confirm', { name: row.name }))) {
        return;
    }

    busy.value = row.id;

    try {
        await axios.delete(url(`/settings/dictionary/${props.kind}/${row.id}`));
        items.splice(index, 1);
    } catch (error) {
        fail(error);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-2">
            <tbody>
                <tr v-for="(row, index) in items" :key="row.id">
                    <td>
                        <input
                            v-model="row.name"
                            type="text"
                            class="form-control form-control-sm"
                            :disabled="busy === row.id || row.is_system"
                            @blur="row.is_system || save(row, index)"
                        />
                    </td>

                    <td v-if="hasColor" style="width: 10rem">
                        <select
                            v-model="row.color"
                            class="form-select form-select-sm"
                            :disabled="busy === row.id"
                            @change="save(row, index)"
                        >
                            <option v-for="color in colors" :key="color" :value="color">{{ color }}</option>
                        </select>
                    </td>

                    <td v-if="hasColor" style="width: 6rem">
                        <span class="badge" :class="`text-bg-${row.color}`">{{ row.name || '—' }}</span>
                    </td>

                    <td v-if="hasVisibility" style="width: 12rem">
                        <div class="form-check mb-0">
                            <input
                                :id="`${kind}-visible-${row.id}`"
                                v-model="row.show_in_list"
                                class="form-check-input"
                                type="checkbox"
                                :disabled="busy === row.id"
                                @change="save(row, index)"
                            />
                            <label class="form-check-label small" :for="`${kind}-visible-${row.id}`">
                                {{ t('dictionaries.show_in_list') }}
                            </label>
                        </div>
                    </td>

                    <td v-if="hasActive" style="width: 8rem">
                        <div class="form-check mb-0">
                            <input
                                :id="`${kind}-active-${row.id}`"
                                v-model="row.is_active"
                                class="form-check-input"
                                type="checkbox"
                                :disabled="busy === row.id"
                                @change="save(row, index)"
                            />
                            <label class="form-check-label small" :for="`${kind}-active-${row.id}`">
                                {{ t('dictionaries.active') }}
                            </label>
                        </div>
                    </td>

                    <td style="width: 3rem" class="text-end">
                        <button
                            v-if="!row.is_system"
                            type="button"
                            class="btn btn-sm btn-link text-danger p-0"
                            :disabled="busy === row.id"
                            :title="t('dictionaries.remove')"
                            @click="remove(row, index)"
                        >
                            <i class="mdi mdi-delete-outline"></i>
                        </button>
                        <i
                            v-else
                            class="mdi mdi-lock-outline text-body-secondary"
                            :title="t('dictionaries.system')"
                        ></i>
                    </td>
                </tr>

                <tr>
                    <td>
                        <input
                            v-model="draft.name"
                            type="text"
                            class="form-control form-control-sm"
                            :placeholder="t('dictionaries.new')"
                            :disabled="busy === 'new'"
                            @keyup.enter="add"
                        />
                    </td>
                    <td v-if="hasColor">
                        <select v-model="draft.color" class="form-select form-select-sm">
                            <option v-for="color in colors" :key="color" :value="color">{{ color }}</option>
                        </select>
                    </td>
                    <td v-if="hasColor">
                        <span class="badge" :class="`text-bg-${draft.color}`">{{ draft.name || '—' }}</span>
                    </td>
                    <td v-if="hasVisibility"></td>
                    <td v-if="hasActive"></td>
                    <td class="text-end">
                        <button
                            type="button"
                            class="btn btn-sm btn-primary"
                            :disabled="busy === 'new' || !draft.name.trim()"
                            @click="add"
                        >
                            <i class="mdi mdi-plus"></i>
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
