import { computed } from 'vue';
import { t } from '@/i18n';

/**
 * Два списка тем на один параметр отбора.
 *
 * Верхний показывает корни, нижний — подтемы выбранного корня, плоско и с
 * путём относительно него: внутри одного корня хватает одинаковых имён, и
 * «Star Wars Episode 4/5/6» без приставки «Buildable Figures» ничего не
 * говорит.
 *
 * В адрес при этом уходит одно и то же `theme_id`, просто иногда в нём корень,
 * а иногда узел поглубже: отбор и так захватывает всё поддерево выбранного
 * узла, а ссылка остаётся прежней и пересылается как была.
 *
 * Корень выводится из того, что выбрано, а не хранится отдельно: если
 * выбранное есть среди подтем — корень тот, что прислал сервер; иначе выбран
 * сам корень.
 */
export function useThemeFilter(props, form) {
    const themeOptions = computed(() => props.themes.map((theme) => ({ value: theme.id, label: theme.path })));

    /*
     * Первым пунктом — возврат ко всей теме.
     *
     * Иначе из подтемы не выбраться: верхний список и так показывает корень,
     * выбрать его повторно нельзя — значение то же, — и остаётся только
     * «Сбросить», который снимает заодно все прочие отборы.
     */
    const subthemeOptions = computed(() => {
        const options = (props.subthemes ?? []).map((theme) => ({ value: theme.id, label: theme.path }));

        return options.length ? [{ value: '', label: t('catalog.whole_theme') }, ...options] : [];
    });

    // Из адреса значение приходит строкой, из списка — числом, и сравнивать их
    // приходится приведёнными: иначе выбранная подтема «214» не узнаётся среди
    // своих же идентификаторов, и верхний список оказывается пустым.
    const chosen = computed(() => (form.theme_id === null || form.theme_id === '' ? null : Number(form.theme_id)));

    const isSubtheme = (value) => (props.subthemes ?? []).some((theme) => theme.id === value);

    const theme = computed({
        get: () => (isSubtheme(chosen.value) ? props.themeRoot : chosen.value),
        // Сменили корень — подтема прежнего корня к нему не относится и
        // уходит вместе с ним.
        set: (value) => (form.theme_id = value),
    });

    const subtheme = computed({
        // Пустое значение — это выбранный пункт «вся тема», а не отсутствие
        // выбора: список подтем появляется только при выбранном корне, и
        // пустая клетка в нём читалась бы как «ничего не выбрано».
        get: () => (isSubtheme(chosen.value) ? chosen.value : ''),
        // Сбросили подтему — остаёмся в корне, а не вовсе без темы. Пустая
        // строка приходит от того самого пункта «вся тема».
        set: (value) => (form.theme_id = (value === '' || value === null || value === undefined)
            ? (props.themeRoot ?? null)
            : value),
    });

    return { themeOptions, subthemeOptions, theme, subtheme };
}
