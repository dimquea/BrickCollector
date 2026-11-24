<script setup>
import { computed } from 'vue';
import AppLink from '@/Components/AppLink.vue';
import { t } from '@/i18n';

const props = defineProps({
    paginator: { type: Object, required: true },
    small: Boolean,
});

const items = computed(() => {
    const {
        current_page: current,
        last_page: last,
        links,
        prev_page_url: previousUrl,
        next_page_url: nextUrl,
    } = props.paginator;
    const visiblePages = new Set([1, 2, current - 1, current, current + 1, last - 1, last]);
    const items = [
        { key: 'previous', url: previousUrl, label: t('pagination.previous'), icon: 'mdi-chevron-left' },
    ];
    let previousPage = 0;

    // Laravel's first and last links are directions. They can also carry page numbers.
    for (const { page, url } of links.slice(1, -1)) {
        if (!visiblePages.has(page)) {
            continue;
        }

        if (page > previousPage + 1) {
            items.push({ key: `gap-${page}`, label: '…', gap: true });
        }

        items.push({ key: page, url, label: page, current: page === current });
        previousPage = page;
    }

    items.push({ key: 'next', url: nextUrl, label: t('pagination.next'), icon: 'mdi-chevron-right' });

    return items;
});
</script>

<template>
    <nav v-if="paginator.last_page > 1" :aria-label="t('pagination.label')">
        <ul class="pagination justify-content-center flex-wrap" :class="{ 'pagination-sm mb-2': small }">
            <li
                v-for="item in items"
                :key="item.key"
                class="page-item"
                :class="{ active: item.current, disabled: !item.url }"
                :aria-hidden="item.gap || undefined"
            >
                <component
                    :is="item.url ? AppLink : 'span'"
                    v-bind="item.url ? { href: item.url, preserveScroll: true } : {}"
                    class="page-link text-center"
                    :aria-current="item.current ? 'page' : undefined"
                    :aria-label="item.icon && item.label"
                    :aria-disabled="(item.icon && !item.url) || undefined"
                    :role="item.icon && !item.url ? 'link' : undefined"
                >
                    <span
                        v-if="item.icon"
                        class="d-inline-flex align-items-center gap-1"
                        :class="{ 'flex-row-reverse': item.key === 'next' }"
                    >
                        <i class="mdi" :class="item.icon" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline">{{ item.label }}</span>
                    </span>
                    <template v-else>{{ item.label }}</template>
                </component>
            </li>
        </ul>
    </nav>
</template>

<style scoped>
@media (max-width: 575.98px) {
    .pagination {
        --bs-pagination-font-size: 0.875rem;
        --bs-pagination-padding-x: 0.375rem;
    }

    .page-link {
        min-width: 1.5rem;
    }
}
</style>
