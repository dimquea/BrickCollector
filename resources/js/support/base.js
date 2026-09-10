/**
 * Путь, под которым приложение видно браузеру.
 *
 * В обычной установке — пустая строка. Под Home Assistant Ingress — префикс
 * вида /api/hassio_ingress/<токен>, который сервер положил в window.__base.
 *
 * Почти всё уходит через axios (им же пользуется Inertia), поэтому достаточно
 * его baseURL. Руками префикс нужен только там, где адрес попадает прямо в
 * разметку — в src картинки, например.
 */
export const base = typeof window === 'undefined' ? '' : (window.__base ?? '');

export function url(path) {
    return base + path;
}
