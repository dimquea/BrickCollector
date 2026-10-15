/**
 * Пункты меню выгрузки.
 *
 * Видов три, и по виду файла их не отличить: опись и список желаемого — один и
 * тот же корень INVENTORY, а BrickStore читает оба. Поэтому выбирают до
 * загрузки, по названию, а не после — по тому, что получилось.
 *
 * Вид едет в адресе: выгрузка — обычная ссылка, её открывают в новой вкладке и
 * кладут в закладки вместе с фильтрами списка.
 */
const LABELS = {
    inventory: 'export.inventory',
    wanted: 'export.wanted',
    bsx: 'export.bsx',
};

/**
 * @param {string} href базовый адрес выгрузки, уже с фильтрами списка
 * @param {string[]} modes какие виды предлагать и в каком порядке
 */
export function exportItems(href, modes = ['inventory', 'wanted', 'bsx']) {
    return modes.map((mode) => ({
        key: mode,
        label: LABELS[mode],
        href: href + (href.includes('?') ? '&' : '?') + 'as=' + mode,
    }));
}
