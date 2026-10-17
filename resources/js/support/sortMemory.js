import { onMounted, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

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
 * @param {object} form     реактивная форма списка с полями sort и dir; страница
 *                          сама перезапрашивает список, когда они меняются
 */
export function rememberSort(section, form) {
    const page = usePage();

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

        // Сообщение, с которым на страницу пришли, — «набор удалён», «набор
        // расформирован», — сервер отдаёт один раз, и перезапрос с запомненным
        // порядком вернулся бы уже без него. Человек ушёл бы со страницы, так
        // и не узнав, чем кончилось действие.
        const arrived = page.props.flash;

        if (arrived?.message) {
            // Первым «успехом» может оказаться сам переход, который сюда
            // привёл: он заканчивается уже после того, как страница
            // смонтирована. У него сообщение своё, и ждать надо следующего.
            const stop = router.on('success', (event) => {
                if (event.detail.page.props.flash?.message) {
                    return;
                }

                stop();
                page.props.flash = arrived;
            });
        }

        // Перезапрос делает сама страница: она следит за порядком и отправляет
        // форму, как только он меняется. Отправить её ещё и отсюда значило бы
        // два одинаковых запроса на каждый заход, и второй стирал бы то, что
        // вернул первый.
        form.sort = saved.sort;
        form.dir = saved.dir;
    });

    watch(() => [form.sort, form.dir], () => write(section, { sort: form.sort, dir: form.dir }));
}
