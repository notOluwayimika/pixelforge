<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

const view = computed(() => {
    switch (props.generation.status) {
        case 'submitting':
            // More than one attempt means Pixelforge pushed back (rate/concurrency limit, overload).
            return props.generation.submit_attempts > 1
                ? {
                      label: 'Waiting for a free slot',
                      class: 'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100',
                      busy: true,
                  }
                : {
                      label: 'Submitting',
                      class: 'bg-muted text-foreground',
                      busy: true,
                  };
        case 'queued':
            return {
                label: 'Queued',
                class: 'bg-muted text-foreground',
                busy: true,
            };
        case 'processing':
            return {
                label: 'Processing',
                class: 'bg-blue-100 text-blue-900 dark:bg-blue-900/40 dark:text-blue-100',
                busy: true,
            };
        case 'succeeded':
            return {
                label: 'Succeeded',
                class: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100',
                busy: false,
            };
        case 'failed':
            return {
                label: 'Failed',
                class: 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-100',
                busy: false,
            };
        default:
            return { label: props.generation.status, class: '', busy: false };
    }
});
</script>

<template>
    <Badge variant="outline" :class="['border-transparent', view.class]">
        <Spinner v-if="view.busy" class="size-3" />
        {{ view.label }}
    </Badge>
</template>
