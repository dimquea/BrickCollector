<script setup>
import { t } from '@/i18n';

/**
 * The condition of an owned copy, as icon-only badges.
 *
 * Two of them come from the status dictionary and two are worked out from the
 * contents, but on a card they are the same kind of fact and read better
 * together. Icon only: a row of cards has no room for words, and the title
 * carries the meaning for anyone who needs it.
 */
defineProps({
    statusCodes: { type: Array, default: () => [] },
    incomplete: { type: Boolean, default: false },
    missingFigs: { type: Boolean, default: false },
});

// Only the seeded statuses get an icon: the user's own entries have names,
// not meanings this component could draw.
const known = {
    box: { icon: 'mdi-package-variant-closed', label: 'status.box' },
    manual: { icon: 'mdi-book-open-page-variant-outline', label: 'status.manual' },
};
</script>

<template>
    <span class="d-inline-flex flex-wrap gap-1 align-items-center">
        <span
            v-for="code in statusCodes.filter((c) => known[c])"
            :key="code"
            class="badge text-bg-success"
            :title="t(known[code].label)"
        >
            <i class="mdi" :class="known[code].icon"></i>
        </span>

        <span
            v-if="incomplete"
            class="badge text-bg-warning"
            :title="t('collection.incomplete_hint')"
        >
            <i class="mdi mdi-alert-outline"></i>
        </span>

        <span
            v-if="missingFigs"
            class="badge text-bg-warning"
            :title="t('collection.missing_figs_hint')"
        >
            <i class="mdi mdi-account-alert-outline"></i>
        </span>
    </span>
</template>
