/**
 * Delays a call until the input has been quiet for `wait` ms.
 *
 * Six lines rather than a dependency: this is the only thing lodash would have
 * been pulled in for.
 */
export function debounce(fn, wait = 300) {
    let timer;

    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
}
