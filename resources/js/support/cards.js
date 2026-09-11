/**
 * Классы сетки карточек по выбранному размеру.
 *
 * Размер выбирается отдельно для узкого и широкого экрана, поэтому решает не
 * JavaScript, а CSS: обе настройки приезжают классами, а какая из них сработает,
 * определяет ширина окна. Иначе пришлось бы следить за resize и перерисовывать
 * список на каждое изменение размера окна.
 */
export function masonry(size) {
    return [
        'masonry',
        size?.desktop === 'small' ? 'masonry--desktop-small' : '',
        size?.mobile === 'small' ? 'masonry--mobile-small' : '',
    ].filter(Boolean);
}
