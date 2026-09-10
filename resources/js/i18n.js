import { ref, shallowRef } from 'vue';

/**
 * Translation helper.
 *
 * The dictionary comes from Laravel through Inertia shared props, so language
 * files stay the single source of truth. See CLAUDE.md, "Internationalisation".
 */
// Refs, not plain variables: t() is called during render, so reading a ref
// registers the dictionary as a dependency and a language switch re-renders
// every component that shows text. With plain variables the values change but
// nothing repaints.
const dictionary = shallowRef({});
export const locale = ref('en');

export function setTranslations(next, nextLocale) {
    dictionary.value = next ?? {};
    locale.value = nextLocale ?? 'en';
}

function lookup(key) {
    return key.split('.').reduce((carry, part) => carry?.[part], dictionary.value);
}

function interpolate(line, replacements) {
    return Object.entries(replacements).reduce(
        (carry, [name, value]) => carry.replaceAll(`:${name}`, value),
        line,
    );
}

/** Translate a key. Falls back to the key itself so a miss is visible, not silent. */
export function t(key, replacements = {}) {
    const line = lookup(key);

    return typeof line === 'string' ? interpolate(line, replacements) : key;
}

/**
 * Pluralised translation, mirroring Laravel's trans_choice format.
 * Plural rules come from the browser, never hand-written: Russian has three
 * forms and getting them right by hand is a classic source of bugs.
 */
export function tChoice(key, count, replacements = {}) {
    const line = lookup(key);

    if (typeof line !== 'string') {
        return key;
    }

    const segments = line.split('|');

    for (const segment of segments) {
        const exact = segment.match(/^\s*\{(\d+)\}\s*/);
        if (exact && Number(exact[1]) === count) {
            return interpolate(segment.slice(exact[0].length), { count, ...replacements });
        }

        const range = segment.match(/^\s*\[(\d+),\s*(\d+|\*)\]\s*/);
        if (range) {
            const [, from, to] = range;
            if (count >= Number(from) && (to === '*' || count <= Number(to))) {
                return interpolate(segment.slice(range[0].length), { count, ...replacements });
            }
        }
    }

    const category = new Intl.PluralRules(locale.value).select(count);
    const order = ['zero', 'one', 'two', 'few', 'many', 'other'];
    const index = Math.min(order.indexOf(category), segments.length - 1);

    return interpolate(segments[Math.max(index, 0)] ?? segments.at(-1), { count, ...replacements });
}
