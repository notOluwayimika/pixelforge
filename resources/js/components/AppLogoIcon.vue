<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- A picture frame with a sun and hills: "an image, generated". Uses currentColor. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        :class="className"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <path
            fill-rule="evenodd"
            d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 2v9.6l3.3-3.3a1 1 0 0 1 1.4 0l4.3 4.3 1.8-1.8a1 1 0 0 1 1.4 0L19 15.6V5H5Zm11 4.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0Z"
        />
    </svg>
</template>
