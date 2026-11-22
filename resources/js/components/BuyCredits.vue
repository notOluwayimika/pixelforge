<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import CreditsController from '@/actions/App/Http/Controllers/CreditsController';
import { Button } from '@/components/ui/button';

withDefaults(defineProps<{ packs?: number[] }>(), {
    packs: () => [1, 5, 20],
});

const buying = ref<number | null>(null);

/** Fake checkout: posts a purchase and reloads the page props (including the shared balance). */
function buy(pack: number) {
    buying.value = pack;
    router.post(
        CreditsController.purchase.url(),
        { pack },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => (buying.value = null),
        },
    );
}
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <Button
            v-for="pack in packs"
            :key="pack"
            type="button"
            variant="outline"
            size="sm"
            :disabled="buying !== null"
            @click="buy(pack)"
        >
            Buy ${{ pack }}
        </Button>
    </div>
</template>
