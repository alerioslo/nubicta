<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const message = ref(null);
let timer = null;

watch(
    () => page.props.flash,
    (flash) => {
        const text = flash?.success || flash?.error;
        if (!text) return;
        message.value = { text, type: flash.error ? 'error' : 'success' };
        clearTimeout(timer);
        timer = setTimeout(() => (message.value = null), 5000);
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <transition enter-from-class="opacity-0 translate-y-2" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
        <div
            v-if="message"
            class="fixed bottom-6 right-6 z-[60] flex max-w-[420px] items-start gap-3 rounded-[8px] px-4 py-3 text-white shadow-lg"
            :class="message.type === 'error' ? 'bg-danger' : 'bg-ok'"
        >
            <Icon :icon="message.type === 'error' ? 'triangle-exclamation' : 'check'" class="mt-[2px]" />
            <span>{{ message.text }}</span>
            <button type="button" class="ml-2 opacity-80 hover:opacity-100" @click="message = null"><Icon icon="xmark" /></button>
        </div>
    </transition>
</template>
