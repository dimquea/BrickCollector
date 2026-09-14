<script setup>
import axios from 'axios';
import { url } from '@/support/base';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Modal } from 'bootstrap';
import ItemImage from '@/Components/ItemImage.vue';
import ColorDot from '@/Components/ColorDot.vue';
import { t } from '@/i18n';

/**
 * Поиск предмета по фотографии.
 *
 * Единственное место, откуда наружу уходят данные человека, поэтому отправка —
 * отдельное действие: сначала выбирают снимок, и только потом, нажав «Найти»,
 * соглашаются его отправить. Предупреждение стоит здесь же, у кнопки, а не
 * только в настройках, где его прочли один раз и забыли.
 *
 * Кандидаты приходят с сервера уже строками нашего справочника: чужие миниатюры
 * не используются — свои у нас есть, а их условия запрещают копировать чужое.
 *
 * Переход открывается после того, как модалка закроется. Уйти со страницы при
 * открытой модалке — значит оставить её подложку висеть над следующей.
 */
const el = ref(null);
const picker = ref(null);
let modal = null;
let pending = null;

const file = ref(null);
const preview = ref(null);
const busy = ref(false);
const error = ref(null);
const items = ref([]);
const colours = ref([]);
const searched = ref(false);

function forget() {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
        preview.value = null;
    }
}

function reset() {
    forget();
    file.value = null;
    busy.value = false;
    error.value = null;
    items.value = [];
    colours.value = [];
    searched.value = false;

    if (picker.value) {
        picker.value.value = '';
    }
}

function open() {
    reset();
    modal.show();
}

defineExpose({ open });

function choose(event) {
    const chosen = event.target.files?.[0] ?? null;

    forget();
    file.value = chosen;
    preview.value = chosen ? URL.createObjectURL(chosen) : null;
    items.value = [];
    colours.value = [];
    searched.value = false;
    error.value = null;
}

/** Самый вероятный цвет: он же подставится в карточку справочника. */
const colour = computed(() => colours.value[0] ?? null);

function href(item) {
    const base = `/catalog/${item.type}/${encodeURIComponent(item.id)}`;

    // Цвет осмыслен только у детали; у набора и фигурки его нет.
    return item.type === 'P' && colour.value ? `${base}?color=${colour.value.id}` : base;
}

/**
 * Сколько пикселей по длинной стороне уходит наружу.
 *
 * С телефона снимок приходит на несколько мегабайт, а службе такой размер не
 * нужен. Но и мельчить нельзя: у детали с принтом весь смысл в мелком рисунке,
 * и, потеряв его, мы получим уверенное «не то» вместо честного «не знаю».
 * Тысяча с небольшим — это и десятикратная экономия, и читаемая печать.
 */
const MAX_SIDE = 1024;

/**
 * Уменьшает снимок перед отправкой — в браузере, до того как он покинет дом.
 *
 * Всё, что и так меньше, уходит как есть: пересжимать нечего, а лишнее
 * преобразование только портит. Не заладилось — отправляем оригинал:
 * уменьшение здесь удобство, а не условие работы.
 */
async function shrink(source) {
    if (typeof createImageBitmap !== 'function') {
        return source;
    }

    try {
        const bitmap = await createImageBitmap(source);
        const side = Math.max(bitmap.width, bitmap.height);

        if (side <= MAX_SIDE) {
            bitmap.close?.();

            return source;
        }

        const scale = MAX_SIDE / side;
        const canvas = document.createElement('canvas');

        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);

        const context = canvas.getContext('2d');

        // Прозрачность превратилась бы в чёрное поле: белый фон и ближе к
        // правде, и дружелюбнее к распознаванию.
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close?.();

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.85));

        return blob ?? source;
    } catch {
        return source;
    }
}

async function find() {
    if (! file.value || busy.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    const photo = await shrink(file.value);
    const body = new FormData();

    // Имя оригинала сохраняется, когда файл ушёл нетронутым: по картинке сервер
    // разбирает, что ему прислали, и подменять имя без нужды незачем.
    body.append('photo', photo, photo === file.value ? file.value.name : 'photo.jpg');

    try {
        const { data } = await axios.post(url('/catalog/recognise'), body);

        items.value = data.items;
        colours.value = data.colours;
    } catch (reason) {
        error.value = reason.response?.data?.message ?? t('recognition.failed');
    } finally {
        busy.value = false;
        searched.value = true;
    }
}

function openItem(item) {
    pending = href(item);
    modal.hide();
}

function onHidden() {
    forget();

    if (pending) {
        const target = pending;

        pending = null;
        router.visit(url(target));
    }
}

onMounted(() => {
    modal = new Modal(el.value);
    el.value.addEventListener('hidden.bs.modal', onHidden);
});

onBeforeUnmount(() => {
    el.value?.removeEventListener('hidden.bs.modal', onHidden);
    forget();
    modal?.dispose();
});
</script>

<template>
    <div ref="el" class="modal fade" tabindex="-1" aria-labelledby="photoSearchTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="photoSearchTitle" class="modal-title fs-5">
                        <i class="mdi mdi-image-search-outline me-1"></i>
                        {{ t('recognition.search') }}
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" :aria-label="t('parts.cancel')"></button>
                </div>

                <div class="modal-body">
                    <!-- Отдельной кнопки «снять камерой» здесь нет намеренно.
                         Под панелью Home Assistant приложение живёт в iframe,
                         которому камера не разрешена, и capture там молча
                         оборачивается тем же выбором файла — обещать съёмку
                         значило бы обещать то, чего в аддоне не произойдёт.
                         Системный выбор на телефоне и так предлагает снять. -->
                    <label for="photoFile" class="form-label">{{ t('recognition.file') }}</label>
                    <input
                        id="photoFile"
                        ref="picker"
                        type="file"
                        accept="image/*"
                        class="form-control"
                        @change="choose"
                    />

                    <div v-if="preview" class="text-center my-3">
                        <img :src="preview" :alt="t('recognition.search')" class="img-fluid rounded" style="max-height: 14rem" />
                    </div>

                    <div class="alert alert-warning small d-flex gap-2 mt-3 mb-0">
                        <i class="mdi mdi-cloud-upload-outline flex-shrink-0"></i>
                        <span>{{ t('recognition.warning') }}</span>
                    </div>

                    <div v-if="error" class="alert alert-danger small mt-3 mb-0">{{ error }}</div>

                    <template v-if="items.length">
                        <hr />

                        <p v-if="colour" class="small text-body-secondary d-flex align-items-center gap-2 mb-2">
                            <span>{{ t('recognition.colour_guess') }}:</span>
                            <ColorDot :rgb="colour.rgb" :name="colour.name" />
                        </p>

                        <!-- Кликается вся строка, отдельной кнопки нет: на узком
                             экране она не влезала и растягивала модалку вбок.
                             Кнопка, а не div со слушателем, — чтобы строка
                             доставалась и с клавиатуры; внутри только строчная
                             разметка, блочной кнопке нельзя. -->
                        <div class="list-group list-group-flush">
                            <button
                                v-for="item in items"
                                :key="`${item.type}/${item.id}`"
                                type="button"
                                class="list-group-item list-group-item-action d-flex gap-3 align-items-center px-0"
                                :title="t('recognition.open')"
                                @click="openItem(item)"
                            >
                                <ItemImage
                                    :type="item.type"
                                    :id="item.id"
                                    :color-id="item.type === 'P' && colour ? colour.id : item.image_color_id"
                                    :alt="item.name"
                                    style="width: 4rem"
                                />

                                <span class="flex-grow-1 text-start">
                                    <span class="d-block line-clamp-2">{{ item.name }}</span>
                                    <span class="d-flex align-items-center gap-1 mt-1">
                                        <span class="badge text-bg-light border">{{ item.id }}</span>
                                        <span
                                            class="badge text-bg-light"
                                            :title="t('recognition.score')"
                                        >{{ Math.round(item.score * 100) }}%</span>
                                    </span>
                                </span>

                                <i class="mdi mdi-chevron-right text-body-secondary flex-shrink-0"></i>
                            </button>
                        </div>
                    </template>

                    <p v-else-if="searched && ! error" class="text-body-secondary mt-3 mb-0">
                        {{ t('recognition.nothing') }}
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        {{ t('parts.cancel') }}
                    </button>
                    <button type="button" class="btn btn-primary" :disabled="! file || busy" @click="find">
                        <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>
                        {{ busy ? t('recognition.searching') : t('recognition.find') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
