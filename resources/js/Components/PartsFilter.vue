<script setup>
import axios from 'axios';
import { computed, onMounted, reactive, watch } from 'vue';
import SearchSelect from '@/Components/SearchSelect.vue';
import { url } from '@/support/base';
import { debounce } from '@/support/debounce';
import { t } from '@/i18n';

/**
 * Поиск набора по деталям: строки «артикул + цвет», сколько угодно до десяти.
 *
 * Наружу уходит одна строка для адреса — «3001:11,3023:», пустой цвет значит
 * «любой», — а всё остальное живёт здесь: что вписано, узнана ли деталь, в
 * каких цветах она бывает. В поиск идут только узнанные строки: недописанный
 * артикул не должен опустошать список, пока его дописывают.
 *
 * В поле вписывают артикул, а иногда номер из инструкции: 4243815 называет
 * деталь вместе с цветом, и тогда в строке оказываются оба. Цвета в списке —
 * только те, в которых деталь встречается в описях: в другом её не найдётся
 * ни в одном наборе.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const LIMIT = 10;

let counter = 0;

function row(input = '', colour = '') {
    const created = reactive({ key: ++counter, input, id: null, name: null, colour, colours: [], state: 'idle' });

    // Своя задержка у каждой строки: общая отменила бы поиск в первой, пока
    // дописывают вторую.
    created.later = debounce(() => lookup(created), 400);

    return created;
}

const rows = reactive([]);

/** Узнаёт деталь по вписанному; ответ, пришедший к уже переписанной строке, отбрасывается. */
async function lookup(target) {
    const asked = target.input.trim();

    if (asked === '') {
        Object.assign(target, { id: null, name: null, colours: [], state: 'idle' });

        return;
    }

    target.state = 'loading';

    try {
        const { data } = await axios.get(url('/catalog/parts/lookup'), { params: { q: asked } });

        if (target.input.trim() !== asked) {
            return;
        }

        if (! data.found) {
            Object.assign(target, { id: null, name: null, colours: [], state: 'missing' });

            return;
        }

        target.id = data.id;
        target.name = data.name;
        target.colours = data.colours;
        target.state = 'found';

        // Номер из инструкции назвал цвет — он и выбран. Иначе выбранный
        // остаётся, если деталь в нём бывает.
        if (data.color_id !== null) {
            target.colour = String(data.color_id);
        } else if (target.colour !== '' && ! data.colours.some((c) => String(c.id) === target.colour)) {
            target.colour = '';
        }

        // Номер из инструкции заменяется артикулом: в адрес уходит деталь, а
        // не то, как её назвали.
        if (data.id !== asked) {
            target.input = data.id;
        }
    } catch {
        target.state = 'missing';
    }
}

const colourOptions = (target) => [
    { value: '', label: t('catalog.parts_any_colour') },
    ...target.colours.map((c) => ({ value: String(c.id), label: c.name, rgb: c.rgb })),
];

const serialized = computed(() => rows
    .filter((r) => r.state === 'found' && r.id)
    .map((r) => `${r.id}:${r.colour ?? ''}`)
    .join(','));

watch(serialized, (value) => {
    if (value !== props.modelValue) {
        emit('update:modelValue', value);
    }
});

function add() {
    if (rows.length < LIMIT) {
        rows.push(row());
    }
}

function remove(index) {
    rows.splice(index, 1);

    if (! rows.length) {
        rows.push(row());
    }
}

/** Сброс фильтров снаружи стирает и строки. */
watch(() => props.modelValue, (value) => {
    if (value === '' && serialized.value !== '') {
        rows.splice(0, rows.length, row());
    }
});

onMounted(() => {
    // Строки — из адреса: ссылку с отбором пересылают, и открыться она должна
    // с теми же деталями, уже узнанными.
    for (const pair of props.modelValue.split(',').filter(Boolean).slice(0, LIMIT)) {
        const [id, colour = ''] = pair.split(':');
        const restored = row(id, colour);

        rows.push(restored);
        lookup(restored);
    }

    if (! rows.length) {
        rows.push(row());
    }
});
</script>

<template>
    <div class="d-flex flex-column gap-2">
        <div v-for="(item, index) in rows" :key="item.key" class="row g-2 align-items-start">
            <div class="col-12 col-sm-5">
                <input
                    v-model="item.input"
                    type="search"
                    class="form-control"
                    :class="{ 'is-invalid': item.state === 'missing' }"
                    :placeholder="t('catalog.parts_item_hint')"
                    :aria-label="t('catalog.parts_item')"
                    @input="item.later()"
                />
                <div v-if="item.state === 'missing'" class="invalid-feedback">{{ t('catalog.parts_unknown') }}</div>
                <div v-else-if="item.name" class="form-text text-truncate" :title="item.name">{{ item.name }}</div>
            </div>

            <div class="col">
                <SearchSelect
                    v-model="item.colour"
                    :options="colourOptions(item)"
                    :placeholder="t('catalog.parts_any_colour')"
                />
            </div>

            <div class="col-auto d-flex gap-1">
                <button
                    v-if="rows.length > 1 || item.input"
                    type="button"
                    class="btn btn-outline-secondary"
                    :title="t('catalog.parts_remove')"
                    :aria-label="t('catalog.parts_remove')"
                    @click="remove(index)"
                >
                    <i class="mdi mdi-close"></i>
                </button>
                <button
                    v-if="index === rows.length - 1"
                    type="button"
                    class="btn btn-outline-primary"
                    :disabled="rows.length >= LIMIT"
                    :title="t('catalog.parts_add')"
                    :aria-label="t('catalog.parts_add')"
                    @click="add"
                >
                    <i class="mdi mdi-plus"></i>
                </button>
            </div>
        </div>
    </div>
</template>
