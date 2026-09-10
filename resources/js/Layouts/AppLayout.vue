<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import ToastHost from '@/Components/ToastHost.vue';
import { t } from '@/i18n';

const page = usePage();

// Only the sections that exist are navigable; the rest stay disabled until
// their pages land.
const links = computed(() => [
    { key: 'catalog', href: '/catalog' },
    { key: 'sets', href: '/sets' },
    { key: 'parts', href: '/parts' },
    { key: 'minifigures', href: null },
    { key: 'analytics', href: null },
    { key: 'settings', href: '/settings' },
]);

const isActive = (href) => href !== null && page.url.startsWith(href);
</script>

<template>
    <div class="min-vh-100 d-flex flex-column bg-body-tertiary">
        <nav class="navbar navbar-expand-lg bg-body border-bottom">
            <div class="container">
                <Link class="navbar-brand d-flex align-items-center gap-2" href="/">
                    <i class="mdi mdi-toy-brick-outline fs-4 text-primary"></i>
                    <span class="fw-semibold">BrickCollector</span>
                </Link>

                <button
                    class="navbar-toggler"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#mainNav"
                >
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div id="mainNav" class="collapse navbar-collapse">
                    <ul class="navbar-nav ms-auto">
                        <li v-for="link in links" :key="link.key" class="nav-item">
                            <Link
                                v-if="link.href"
                                class="nav-link"
                                :class="{ active: isActive(link.href) }"
                                :href="link.href"
                            >
                                {{ t(`nav.${link.key}`) }}
                            </Link>
                            <span v-else class="nav-link disabled">{{ t(`nav.${link.key}`) }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <main class="container flex-grow-1 py-4">
            <slot />
        </main>

        <ToastHost />
    </div>
</template>
