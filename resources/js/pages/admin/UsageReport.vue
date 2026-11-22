<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Check, TriangleAlert, X } from '@lucide/vue';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { report } from '@/routes/admin';
import type { UsageReport } from '@/types';

const props = defineProps<UsageReport & { errors?: Record<string, string> }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin report', href: report() }],
    },
});

const range = reactive({ start: props.start, end: props.end });

function apply() {
    router.get(report.url(), { ...range }, { preserveState: true });
}

/** "0.046125000" becomes "$0.046125000": nine decimals, to compare with /v1/usage by eye. */
function usd(amount: string) {
    return amount.startsWith('-') ? `-$${amount.slice(1)}` : `$${amount}`;
}
</script>

<template>
    <Head title="Admin report" />

    <div class="flex flex-col gap-6 p-4 md:p-8">
        <Heading
            title="Admin report"
            description="Per UTC day of completion (the day Pixelforge bills on) and per user. Amounts are exact USD."
        />

        <form class="flex flex-wrap items-end gap-4" @submit.prevent="apply">
            <div class="grid gap-2">
                <Label for="start">From</Label>
                <Input id="start" v-model="range.start" type="date" />
            </div>
            <div class="grid gap-2">
                <Label for="end">To</Label>
                <Input id="end" v-model="range.end" type="date" />
            </div>
            <Button type="submit">Apply</Button>
            <InputError :message="errors?.start ?? errors?.end" />
        </form>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border p-4">
                <div class="text-sm text-muted-foreground">Credits spent</div>
                <div class="text-xl font-semibold tabular-nums">
                    {{ usd(totals.charged) }}
                </div>
            </div>
            <div class="rounded-xl border p-4">
                <div class="text-sm text-muted-foreground">Pixelforge cost</div>
                <div class="text-xl font-semibold tabular-nums">
                    {{ usd(totals.provider_cost) }}
                </div>
            </div>
            <div class="rounded-xl border p-4">
                <div class="text-sm text-muted-foreground">Profit</div>
                <div class="text-xl font-semibold tabular-nums">
                    {{ usd(totals.profit) }}
                </div>
            </div>
            <div class="rounded-xl border p-4">
                <div class="text-sm text-muted-foreground">Generations</div>
                <div class="text-xl font-semibold tabular-nums">
                    {{ totals.generations }}
                    <span class="text-sm font-normal text-muted-foreground">
                        + {{ totals.failed }} failed (free)
                    </span>
                </div>
            </div>
        </div>

        <div
            v-if="pixelforge_error"
            class="flex items-center gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
        >
            <TriangleAlert class="size-4" /> {{ pixelforge_error }}
        </div>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="p-3 font-medium">Day / user</th>
                        <th class="p-3 text-right font-medium">Generations</th>
                        <th class="p-3 text-right font-medium">Charged</th>
                        <th class="p-3 text-right font-medium">
                            Provider cost
                        </th>
                        <th class="p-3 text-right font-medium">
                            Pixelforge says
                        </th>
                        <th class="p-3 text-right font-medium">Profit</th>
                    </tr>
                </thead>
                <tbody v-if="days.length === 0">
                    <tr>
                        <td
                            colspan="6"
                            class="p-6 text-center text-muted-foreground"
                        >
                            No completed generations in this range.
                        </td>
                    </tr>
                </tbody>
                <tbody v-for="day in days" :key="day.date" class="border-t">
                    <tr class="font-medium">
                        <td class="p-3">{{ day.date }}</td>
                        <td class="p-3 text-right tabular-nums">
                            {{ day.generations }}
                            <span
                                v-if="day.failed"
                                class="font-normal text-muted-foreground"
                            >
                                (+{{ day.failed }} failed)
                            </span>
                        </td>
                        <td class="p-3 text-right tabular-nums">
                            {{ usd(day.charged) }}
                        </td>
                        <td class="p-3 text-right tabular-nums">
                            {{ usd(day.provider_cost) }}
                        </td>
                        <td class="p-3 text-right tabular-nums">
                            <span
                                v-if="day.pixelforge"
                                class="inline-flex items-center gap-1"
                                :class="
                                    day.pixelforge.matches
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : 'text-red-700 dark:text-red-400'
                                "
                                :title="
                                    day.pixelforge.matches
                                        ? 'Matches Pixelforge exactly'
                                        : `Pixelforge billed ${day.pixelforge.generations} generation(s). A mismatch today can mean a webhook has not been processed yet.`
                                "
                            >
                                <Check
                                    v-if="day.pixelforge.matches"
                                    class="size-4"
                                />
                                <X v-else class="size-4" />
                                {{ usd(day.pixelforge.provider_cost) }}
                            </span>
                            <span v-else class="text-muted-foreground"
                                >n/a</span
                            >
                        </td>
                        <td class="p-3 text-right tabular-nums">
                            {{ usd(day.profit) }}
                        </td>
                    </tr>
                    <tr
                        v-for="user in day.users"
                        :key="user.user_id"
                        class="text-muted-foreground"
                    >
                        <td class="py-2 pr-3 pl-8">
                            {{ user.name }}
                            <span class="text-xs">{{ user.email }}</span>
                        </td>
                        <td class="p-2 pr-3 text-right tabular-nums">
                            {{ user.generations }}
                            <span v-if="user.failed">(+{{ user.failed }})</span>
                        </td>
                        <td class="p-2 pr-3 text-right tabular-nums">
                            {{ usd(user.charged) }}
                        </td>
                        <td class="p-2 pr-3 text-right tabular-nums">
                            {{ usd(user.provider_cost) }}
                        </td>
                        <td class="p-2 pr-3"></td>
                        <td class="p-2 pr-3 text-right tabular-nums">
                            {{ usd(user.profit) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
