<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BuyCredits from '@/components/BuyCredits.vue';
import Heading from '@/components/Heading.vue';
import { index } from '@/routes/credits';

type Entry = {
    id: number;
    kind: 'purchase' | 'hold' | 'release' | 'charge';
    label: string;
    amount: string;
    prompt: string | null;
    created_at: string;
};

defineProps<{ packs: number[]; entries: Entry[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Credits', href: index() }],
    },
});

const page = usePage();
const balance = computed(() => page.props.credits?.formatted ?? '$0.00');
</script>

<template>
    <Head title="Credits" />

    <div class="flex max-w-4xl flex-col gap-6 p-4 md:p-8">
        <Heading
            title="Credits"
            description="Credits are US dollars. Each generation reserves its maximum estimate, then is charged the actual cost when it succeeds. Failures are free."
        />

        <div
            class="flex flex-col gap-4 rounded-xl border p-6 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <div class="text-sm text-muted-foreground">Balance</div>
                <div class="text-3xl font-semibold tabular-nums">
                    {{ balance }}
                </div>
            </div>
            <div class="flex flex-col gap-1 sm:items-end">
                <BuyCredits :packs="packs" />
                <span class="text-xs text-muted-foreground">
                    Demo only: no payment is taken.
                </span>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="p-3 font-medium">When</th>
                        <th class="p-3 font-medium">What</th>
                        <th class="p-3 font-medium">Generation</th>
                        <th class="p-3 text-right font-medium">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="entries.length === 0">
                        <td
                            colspan="4"
                            class="p-6 text-center text-muted-foreground"
                        >
                            No activity yet. Buy credits to start generating.
                        </td>
                    </tr>
                    <tr
                        v-for="entry in entries"
                        :key="entry.id"
                        class="border-t"
                    >
                        <td class="p-3 whitespace-nowrap text-muted-foreground">
                            {{ new Date(entry.created_at).toLocaleString() }}
                        </td>
                        <td class="p-3">{{ entry.label }}</td>
                        <td
                            class="max-w-xs truncate p-3 text-muted-foreground"
                            :title="entry.prompt ?? ''"
                        >
                            {{ entry.prompt ?? '' }}
                        </td>
                        <td
                            class="p-3 text-right tabular-nums"
                            :class="
                                entry.amount.startsWith('-')
                                    ? ''
                                    : 'text-emerald-700 dark:text-emerald-400'
                            "
                        >
                            {{
                                entry.amount.startsWith('-')
                                    ? entry.amount
                                    : `+${entry.amount}`
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
