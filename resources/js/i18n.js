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
 *
 * A segment may name an exact count ({0}) or a range ([2,4]); everything else
 * is a plain form, and the browser says which of those a count takes. Plural
 * rules are never hand-written: Russian has three forms, and spelling them as
 * ranges gets 21 and 41 wrong — [5,*] swallowed them and produced
 * "41 минифигурок" where the language wants "41 минифигурка".
 */
export function tChoice(key, count, replacements = {}) {
    const line = lookup(key);

    if (typeof line !== 'string') {
        return key;
    }

    const plain = [];

    for (const segment of line.split('|')) {
        const exact = segment.match(/^\s*\{(\d+)\}\s*/);
        if (exact) {
            if (Number(exact[1]) === count) {
                return interpolate(segment.slice(exact[0].length), { count, ...replacements });
            }
            continue;
        }

        const range = segment.match(/^\s*\[(\d+),\s*(\d+|\*)\]\s*/);
        if (range) {
            const [, from, to] = range;
            if (count >= Number(from) && (to === '*' || count <= Number(to))) {
                return interpolate(segment.slice(range[0].length), { count, ...replacements });
            }
            continue;
        }

        plain.push(segment);
    }

    if (plain.length === 0) {
        return interpolate(line.split('|').at(-1), { count, ...replacements });
    }

    // Canonical order, so a language's forms line up with the order they are
    // written in: one, few, many for Russian; one, other for English. "other"
    // covers fractions in Russian and falls onto the last form written.
    const rules = new Intl.PluralRules(locale.value);
    const categories = ['zero', 'one', 'two', 'few', 'many', 'other']
        .filter((name) => rules.resolvedOptions().pluralCategories.includes(name));
    const index = Math.min(Math.max(categories.indexOf(rules.select(count)), 0), plain.length - 1);

    return interpolate(plain[index], { count, ...replacements });
}
