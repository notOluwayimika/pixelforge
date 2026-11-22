<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Activity, Receipt, Scale, Wallet } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { create, index } from '@/routes/generations';

const REPOSITORY = 'https://github.com/notOluwayimika/pixelforge';

const features = [
    {
        icon: Receipt,
        title: 'See the price first',
        body: 'Every prompt shows a typical and a maximum cost before you submit. You are never charged more than the maximum.',
    },
    {
        icon: Scale,
        title: 'Exact to the nanodollar',
        body: 'You pay the Pixelforge cost plus 25%, computed in whole billionths of a dollar and reconciled daily against Pixelforge’s own records.',
    },
    {
        icon: Activity,
        title: 'Live progress',
        body: 'Watch each image go from queued to processing to done. Rate limits and busy periods show as waiting, not as errors.',
    },
    {
        icon: Wallet,
        title: 'Failures are free',
        body: 'Credits for the maximum are reserved when you submit; you pay only the actual cost if the image succeeds, and nothing if it fails.',
    },
];
</script>

<template>
    <Head title="Generate images, pay only for what you get" />

    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-5"
        >
            <div class="flex items-center gap-2 font-semibold">
                <span
                    class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-5 fill-current" />
                </span>
                {{ $page.props.name }}
            </div>

            <nav class="flex items-center gap-2">
                <Button v-if="$page.props.auth.user" as-child>
                    <Link :href="index()">Open app</Link>
                </Button>
                <template v-else>
                    <Button variant="ghost" as-child>
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button as-child>
                        <Link :href="register()">Get started</Link>
                    </Button>
                </template>
            </nav>
        </header>

        <main
            class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-16 px-6 py-12 md:py-20"
        >
            <section class="flex max-w-2xl flex-col gap-6">
                <h1
                    class="text-4xl font-semibold tracking-tight text-balance md:text-5xl"
                >
                    Generate images. Pay only for what you get.
                </h1>
                <p class="text-lg text-pretty text-muted-foreground">
                    Describe an image, pick a size and quality, and see exactly
                    what it can cost before you commit. Powered by the
                    Pixelforge API, billed to the billionth of a dollar.
                </p>
                <div class="flex flex-wrap gap-3">
                    <Button size="lg" as-child>
                        <Link
                            :href="
                                $page.props.auth.user ? create() : register()
                            "
                        >
                            {{
                                $page.props.auth.user
                                    ? 'Generate an image'
                                    : 'Create a free account'
                            }}
                        </Link>
                    </Button>
                    <Button size="lg" variant="outline" as-child>
                        <a :href="REPOSITORY" target="_blank" rel="noopener">
                            Read the source
                        </a>
                    </Button>
                </div>
            </section>

            <section class="grid gap-4 sm:grid-cols-2">
                <div
                    v-for="feature in features"
                    :key="feature.title"
                    class="flex flex-col gap-2 rounded-xl border p-6"
                >
                    <component
                        :is="feature.icon"
                        class="size-5 text-muted-foreground"
                    />
                    <h2 class="font-medium">{{ feature.title }}</h2>
                    <p class="text-sm text-pretty text-muted-foreground">
                        {{ feature.body }}
                    </p>
                </div>
            </section>

            <section class="flex flex-col gap-4">
                <h2 class="text-xl font-semibold">How credits work</h2>
                <ol class="grid gap-4 text-sm sm:grid-cols-3">
                    <li class="rounded-xl bg-muted/50 p-5">
                        <span class="font-medium">1. Top up.</span>
                        <span class="text-muted-foreground">
                            Add $1, $5 or $20 of credits. (It’s a demo: no
                            payment is taken.)
                        </span>
                    </li>
                    <li class="rounded-xl bg-muted/50 p-5">
                        <span class="font-medium">2. Reserve.</span>
                        <span class="text-muted-foreground">
                            Submitting holds the maximum estimate, so several
                            images in flight can never overspend.
                        </span>
                    </li>
                    <li class="rounded-xl bg-muted/50 p-5">
                        <span class="font-medium">3. Settle.</span>
                        <span class="text-muted-foreground">
                            On success you pay the actual cost and get the rest
                            back. On failure, everything comes back.
                        </span>
                    </li>
                </ol>
            </section>
        </main>

        <footer
            class="mx-auto w-full max-w-5xl px-6 py-8 text-sm text-muted-foreground"
        >
            A take-home project for PromptEdit.
            <a
                :href="REPOSITORY"
                class="underline underline-offset-4"
                target="_blank"
                rel="noopener"
            >
                Source on GitHub
            </a>
        </footer>
    </div>
</template>
