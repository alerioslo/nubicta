<script setup>
import { Link } from '@inertiajs/vue3';

/* tabs: lista de { label, href? } o strings. active: label activo. */
defineProps({
    title: { type: String, required: true },
    tabs: { type: Array, default: () => [] },
    active: { type: String, default: null },
    back: { type: String, default: null },
});
const emit = defineEmits(['tab']);

const label = (t) => (typeof t === 'string' ? t : t.label);
</script>

<template>
    <div class="pt-[6px]">
        <div class="flex min-h-[44px] items-center gap-3">
            <Link v-if="back" :href="back" class="text-[16px] text-primary"><Icon icon="arrow-left" /></Link>
            <h1 class="text-[22px] font-bold text-ink">{{ title }}</h1>
            <div class="ml-auto flex items-center gap-2"><slot name="actions" /></div>
        </div>
        <div v-if="tabs.length" class="s-tabs mt-[10px]">
            <template v-for="t in tabs" :key="label(t)">
                <Link v-if="typeof t !== 'string' && t.href" :href="t.href" class="s-tab" :class="{ active: label(t) === (active ?? label(tabs[0])) }">{{ label(t) }}</Link>
                <button v-else type="button" class="s-tab" :class="{ active: label(t) === (active ?? label(tabs[0])) }" @click="emit('tab', label(t))">
                    {{ label(t) }}
                </button>
            </template>
        </div>
    </div>
</template>
