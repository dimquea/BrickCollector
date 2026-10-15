import { onMounted, watch } from 'vue';

/**
 * Запоминает порядок списка — для каждого раздела свой.
 *
 * Порядок живёт в адресе, и это правильно: ссылку «детали по количеству»
 * пересылают. Но тот, кто всегда смотрит наборы по году, выбирал год заново
 * при каждом заходе, потому что в закладке и в меню адрес без порядка.
 *
 * Хранится у браузера, а не в настройках: это про привычку сидящего за
 * экраном, а не про коллекцию. Поэтому и раздельно по разделам — наборы смотрят
 * по году, а детали по количеству, и одно другому не указ.
 *
 * Адрес сильнее памяти: пришли по ссылке с порядком — показывается он, и
 * запомненный в этот раз не применяется.
 */
const PREFIX = 'brickcollector.sort.';

/** Хранилище бывает недоступно — приватное окно, запрет на данные сайта. */
function read(key) {
    try {
        const saved = window.localStorage.getItem(PREFIX + key);

        return saved ? JSON.parse(saved) : null;
    } catch {
        return null;
    }
}

function write(key, value) {
    try {
        window.localStorage.setItem(PREFIX + key, JSON.stringify(value));
    } catch {
        // Не сохранилось — не беда: порядок на этой странице уже такой, какой
        // выбрали, а в следующий раз будет обычный.
    }
}

/**
 * @param {string} section  ключ раздела: sets, parts, minifigures, …
 * @param {object} form     реактивная форма списка с полями sort и dir
 * @param {Function} submit перезапрос списка
 */
export function rememberSort(section, form, submit) {
    onMounted(() => {
        const asked = new URLSearchParams(window.location.search);

        // Пришли по ссылке с порядком — показывается он, а не запомненный. И
        // запоминается тоже он: раздел помнит, как на него смотрели в
        // последний раз, а не как его в последний раз настраивали руками.
        if (asked.has('sort') || asked.has('dir')) {
            write(section, { sort: form.sort, dir: form.dir });

            return;
        }

        const saved = read(section);

        if (! saved || (saved.sort === form.sort && saved.dir === form.dir)) {
            return;
        }

        form.sort = saved.sort;
        form.dir = saved.dir;

        submit();
    });

    watch(() => [form.sort, form.dir], () => write(section, { sort: form.sort, dir: form.dir }));
}
