<script setup>
import { nextTick, ref, watch } from 'vue';
import { closeDialog, dialog } from '../composables/dialog';

const input = ref(null);
const accept = ref(null);
watch(() => dialog.open, async (open) => {
    if (!open) return;
    await nextTick();
    (dialog.kind === 'prompt' ? input.value : accept.value)?.focus();
});
</script>

<template>
    <div v-if="dialog.open" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50" @keydown.esc="closeDialog(false)">
        <div class="w-[290px] max-w-[90vw] rounded-[2px] bg-white px-[22px] pb-[16px] pt-[18px] text-center text-[12px] text-[#333] shadow-lg" role="dialog" aria-modal="true">
            <p class="whitespace-pre-line leading-[1.5]">{{ dialog.message }}</p>
            <input v-if="dialog.kind === 'prompt'" ref="input" v-model="dialog.value" class="doc-input mt-[10px] w-full" @keydown.enter.prevent="closeDialog(true)" />
            <div class="mt-[12px] flex justify-center">
                <button ref="accept" type="button" class="doc-btn doc-btn-green h-[28px] px-[10px] text-[12px]" @click="closeDialog(true)">Aceptar</button>
                <button v-if="dialog.kind !== 'alert'" type="button" class="doc-btn doc-btn-blue h-[28px] px-[10px] text-[12px]" @click="closeDialog(false)">Cancelar</button>
            </div>
        </div>
    </div>
</template>
