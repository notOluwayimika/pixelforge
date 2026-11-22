<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { ImageOff, Plus, RotateCw } from '@lucide/vue';
import { ref, watch } from 'vue';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/generations';
import type { Generation } from '@/types';

const props = defineProps<{
    generations: Generation[];
    hasInFlight: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

// Live status: while anything is still in flight, re-fetch just these two props every 3s.
// Webhooks update the database; this is how the page finds out. Stops once all are final.
const { start, stop } = usePoll(
    3000,
    { only: ['generations', 'hasInFlight'] },
    { autoStart: props.hasInFlight },
);

watch(
    () => props.hasInFlight,
    (inFlight) => (inFlight ? start() : stop()),
);

const retrying = ref<string | null>(null);

function retry(generation: Generation) {
    retrying.value = generation.id;
    router.post(
        GenerationController.retry.url(generation.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => (retrying.value = null),
        },
    );
}

const brokenImages = ref(new Set<string>());

function formatDate(iso: string) {
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <Head title="History" />

    <div class="flex flex-col gap-6 p-4 md:p-8">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="History"
                description="Your last 50 generations. Status updates live."
            />
            <Button as-child>
                <Link :href="create()"><Plus /> New generation</Link>
            </Button>
        </div>

        <div
            v-if="generations.length === 0"
            class="rounded-xl border border-dashed p-12 text-center text-muted-foreground"
        >
            No generations yet.
            <Link :href="create()" class="underline"
                >Create your first one.</Link
            >
        </div>

        <ul v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <li
                v-for="generation in generations"
                :key="generation.id"
                class="flex flex-col overflow-hidden rounded-xl border"
            >
                <div
                    class="flex aspect-square items-center justify-center bg-muted"
                >
                    <img
                        v-if="
                            generation.image_url &&
                            !brokenImages.has(generation.id)
                        "
                        :src="generation.image_url"
                        :alt="generation.prompt"
                        class="h-full w-full object-contain"
                        loading="lazy"
                        @error="brokenImages.add(generation.id)"
                    />
                    <div
                        v-else-if="generation.status === 'succeeded'"
                        class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground"
                    >
                        <ImageOff />
                        Image unavailable (Pixelforge links expire after 24h).
                    </div>
                    <GenerationStatusBadge v-else :generation="generation" />
                </div>

                <div class="flex flex-1 flex-col gap-3 p-4">
                    <div class="flex items-center justify-between gap-2">
                        <GenerationStatusBadge :generation="generation" />
                        <span class="text-xs text-muted-foreground">
                            {{ generation.size }} · {{ generation.quality }}
                        </span>
                    </div>

                    <p class="line-clamp-3 text-sm" :title="generation.prompt">
                        {{ generation.prompt }}
                    </p>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <dt class="text-muted-foreground">Estimated</dt>
                        <dd class="text-right tabular-nums">
                            {{ generation.estimated_typical_charge }}
                            <span class="text-xs text-muted-foreground">
                                (max {{ generation.estimated_max_charge }})
                            </span>
                        </dd>
                        <dt class="text-muted-foreground">Charged</dt>
                        <dd class="text-right font-medium tabular-nums">
                            <template v-if="generation.charge !== null">
                                {{ generation.charge }}
                            </template>
                            <span v-else class="text-muted-foreground">
                                pending
                            </span>
                        </dd>
                    </dl>

                    <div
                        v-if="generation.status === 'failed'"
                        class="mt-auto flex items-start justify-between gap-2 rounded-md bg-red-50 p-3 text-sm text-red-900 dark:bg-red-950/40 dark:text-red-100"
                    >
                        <span>
                            {{ generation.error_message }}
                            <span class="block text-xs opacity-70">
                                {{ generation.error_code }} · not charged
                            </span>
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="retrying === generation.id"
                            @click="retry(generation)"
                        >
                            <RotateCw /> Retry
                        </Button>
                    </div>

                    <p class="mt-auto text-xs text-muted-foreground">
                        {{ formatDate(generation.created_at) }}
                    </p>
                </div>
            </li>
        </ul>
    </div>
</template>
