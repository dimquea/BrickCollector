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
    'stock-bsx': 'export.stock_bsx',
    'stock-xml': 'export.stock_xml',
};

/** Подсказка под пунктом: то, о чём надо знать до того, как нажать. */
const HINTS = {
    'stock-xml': 'export.stock_xml_hint',
};

const BASIC = ['inventory', 'wanted', 'bsx'];

/**
 * Виды выгрузки для раздела коллекции, по его отбору.
 *
 * Склад — это то, что выставляют на продажу: партии и экземпляры, а не итоги.
 * Под «недостачей» его нет — продать недостающее нельзя; и нет под отбором
 * «в наборах», «в фигурках», «в сборках»: у детали внутри набора нет своей
 * цены и своего состояния, на склад она не идёт.
 *
 * @param {{ lost?: boolean, placement?: string|null }} form
 */
export function exportModes(form) {
    const placement = form.placement ?? null;
    const stock = ! form.lost && (placement === null || placement === 'loose');

    return stock ? [...BASIC, 'stock-bsx', 'stock-xml'] : BASIC;
}

/**
 * @param {string} href базовый адрес выгрузки, уже с фильтрами списка
 * @param {string[]} modes какие виды предлагать и в каком порядке
 */
export function exportItems(href, modes = BASIC) {
    return modes.map((mode) => ({
        key: mode,
        label: LABELS[mode],
        hint: HINTS[mode] ?? null,
        href: href + (href.includes('?') ? '&' : '?') + 'as=' + mode,
    }));
}
