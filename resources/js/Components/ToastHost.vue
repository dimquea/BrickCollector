<script setup>
import { toasts, dismiss } from '@/support/toasts';

/**
 * Bootstrap toasts, rendered without the Bootstrap JS: the markup is all the
 * component needs, and letting Vue own visibility avoids two things fighting
 * over the same DOM node.
 */
</script>

<template>
    <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090">
        <div
            v-for="toast in toasts"
            :key="toast.id"
            class="toast show align-items-center border-0 mb-2"
            :class="`text-bg-${toast.variant}`"
            role="alert"
            aria-live="assertive"
            aria-atomic="true"
        >
            <div class="d-flex">
                <div class="toast-body">{{ toast.message }}</div>
                <button
                    type="button"
                    class="btn-close btn-close-white me-2 m-auto"
                    aria-label="Close"
                    @click="dismiss(toast.id)"
                ></button>
            </div>
        </div>
    </div>
</template>
