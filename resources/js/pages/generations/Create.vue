<script setup lang="ts">
import { Head, Link, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { Plus, X } from '@lucide/vue';
import { computed, nextTick } from 'vue';
import GenerationController from '@/actions/App/Http/Controllers/GenerationController';
import BuyCredits from '@/components/BuyCredits.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index as creditsIndex } from '@/routes/credits';
import { create } from '@/routes/generations';
import type { Estimate } from '@/types';

const props = defineProps<{
    sizes: string[];
    qualities: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: create() }],
    },
});

const MAX_REFERENCES = 4;
// Bound rather than static: a literal "//" in a static prop trips vue-tsc's code generation.
const REFERENCE_PLACEHOLDER = 'https://example.com/style.png';

// The form that creates the generation (an Inertia visit: redirects to History on success).
const form = useForm({
    prompt: '',
    size: props.sizes[0],
    quality: props.qualities.includes('medium') ? 'medium' : props.qualities[0],
    reference_images: [] as string[],
});

// A separate JSON request for the live estimate. It never navigates; it just fills `response`.
// The server does the maths, so the estimate uses exactly the same code as billing.
const estimate = useHttp<ReturnType<typeof form.data>, Estimate>(form.data());

watchDebounced(
    () => form.data(),
    () => {
        if (form.prompt.trim() === '') {
            return;
        }

        estimate.cancel();
        estimate
            .transform(() => form.data())
            .post(GenerationController.estimate.url())
            .catch(() => {
                /* validation errors are shown when the user submits */
            });
    },
    { debounce: 300, deep: true, immediate: true },
);

const hasPrompt = computed(() => form.prompt.trim() !== '');

const atReferenceLimit = computed(
    () => form.reference_images.length >= MAX_REFERENCES,
);

// Balance (shared by the server on every page). Generating needs the MAX estimate available,
// because that's what gets reserved. The server enforces the same rule; this just explains it early.
const page = usePage();
const credits = computed(() => page.props.credits);
const cannotAfford = computed(
    () =>
        estimate.response !== null &&
        credits.value !== null &&
        credits.value.balance < estimate.response.max_charge,
);
const creditsError = computed(
    () => (form.errors as Record<string, string | undefined>).credits,
);

// Errors come back keyed "reference_images" or "reference_images.2"; show them all together.
const referenceErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('reference_images'))
        .map(([, message]) => message),
);

function addReference() {
    if (atReferenceLimit.value) {
        return;
    }

    form.reference_images.push('');
    // Move focus to the new row so the user can type the URL straight away.
    const index = form.reference_images.length - 1;
    nextTick(() => document.getElementById(`reference-${index}`)?.focus());
}

function removeReference(index: number) {
    form.reference_images.splice(index, 1);
}

function submit() {
    form.post(GenerationController.store.url());
}
</script>

<template>
    <Head title="Generate" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 md:p-8">
        <Heading
            title="Generate an image"
            description="You pay the Pixelforge cost plus 25%, and only if the image succeeds."
        />

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="prompt">Prompt</Label>
                <textarea
                    id="prompt"
                    v-model="form.prompt"
                    rows="4"
                    maxlength="4000"
                    required
                    placeholder="A lighthouse on a cliff at dawn, oil painting"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                ></textarea>
                <div class="flex justify-between text-xs text-muted-foreground">
                    <span>
                        Test keywords: [fail] [slow] [duplicate] [blocked]
                        [overloaded] [succeed]
                    </span>
                    <span>{{ form.prompt.length }} / 4000</span>
                </div>
                <InputError :message="form.errors.prompt" />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="size in sizes"
                            :key="size"
                            type="button"
                            size="sm"
                            :variant="
                                form.size === size ? 'default' : 'outline'
                            "
                            :aria-pressed="form.size === size"
                            @click="form.size = size"
                        >
                            {{ size }}
                        </Button>
                    </div>
                    <InputError :message="form.errors.size" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="quality in qualities"
                            :key="quality"
                            type="button"
                            size="sm"
                            class="capitalize"
                            :variant="
                                form.quality === quality ? 'default' : 'outline'
                            "
                            :aria-pressed="form.quality === quality"
                            @click="form.quality = quality"
                        >
                            {{ quality }}
                        </Button>
                    </div>
                    <InputError :message="form.errors.quality" />
                </fieldset>
            </div>

            <div class="grid gap-2">
                <Label>Reference images (optional)</Label>
                <p class="text-xs text-muted-foreground">
                    Up to {{ MAX_REFERENCES }} image URLs to guide the style.
                    Each one adds 765 input tokens.
                </p>
                <div
                    v-for="(_, i) in form.reference_images"
                    :key="i"
                    class="flex gap-2"
                >
                    <Input
                        v-model="form.reference_images[i]"
                        :id="`reference-${i}`"
                        type="url"
                        :placeholder="REFERENCE_PLACEHOLDER"
                        :aria-label="`Reference image ${i + 1}`"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Remove reference image ${i + 1}`"
                        @click="removeReference(i)"
                    >
                        <X />
                    </Button>
                </div>
                <InputError
                    v-for="message in referenceErrors"
                    :key="message"
                    :message="message"
                />
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="atReferenceLimit"
                        @click="addReference"
                    >
                        <Plus /> Add reference URL
                    </Button>
                </div>
            </div>

            <div
                class="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between"
                aria-live="polite"
            >
                <div class="text-sm">
                    <div class="text-muted-foreground">Estimated cost</div>
                    <div v-if="!hasPrompt" class="text-muted-foreground">
                        Enter a prompt to see the price.
                    </div>
                    <div
                        v-else-if="estimate.response"
                        :class="{ 'opacity-60': estimate.processing }"
                    >
                        <span class="text-2xl font-semibold tabular-nums">
                            about
                            {{ estimate.response.typical_charge_formatted }}
                        </span>
                        <span class="ml-2 text-muted-foreground tabular-nums">
                            at most {{ estimate.response.max_charge_formatted }}
                        </span>
                        <p class="mt-1 text-xs text-muted-foreground">
                            The final price depends on tokens Pixelforge only
                            reports after completion. You will never be charged
                            more than the maximum, and nothing if it fails.
                        </p>
                    </div>
                    <Spinner v-else />
                    <p class="mt-2 text-xs text-muted-foreground">
                        Balance:
                        <Link :href="creditsIndex()" class="underline">
                            {{ credits?.formatted ?? '$0.00' }}
                        </Link>
                    </p>
                </div>

                <Button
                    type="submit"
                    size="lg"
                    :disabled="form.processing || !hasPrompt || cannotAfford"
                >
                    <Spinner v-if="form.processing" />
                    Generate
                </Button>
            </div>

            <div
                v-if="cannotAfford || creditsError"
                class="flex flex-col gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
            >
                <p>
                    {{
                        creditsError ??
                        `This generation can cost up to ${estimate.response?.max_charge_formatted}, but your balance is ${credits?.formatted ?? '$0.00'}. Buy credits to continue.`
                    }}
                </p>
                <BuyCredits />
            </div>
        </form>
    </div>
</template>
