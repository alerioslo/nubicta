<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

/* Boton "Descargar" con las opciones Excel y PDF; emite el formato elegido. */
const props = defineProps({
    formats: { type: Array, default: () => ['xlsx', 'pdf'] },
    buttonClass: { type: String, default: '' },
    label: { type: String, default: 'Descargar' },
    icon: { type: String, default: 'download' },
    title: { type: String, default: null },
});
const emit = defineEmits(['download']);
const open = ref(false);
const root = ref(null);
const OPTIONS = { xlsx: ['Excel', 'file-excel', 'text-[#1d6f42]'], pdf: ['PDF', 'file-pdf', 'text-[#d93025]'] };
const pick = (f) => { open.value = false; emit('download', f); };
const outside = (e) => { if (root.value && !root.value.contains(e.target)) open.value = false; };
onMounted(() => document.addEventListener('click', outside));
onBeforeUnmount(() => document.removeEventListener('click', outside));
</script>

<template>
    <div ref="root" class="relative">
        <button type="button" :class="buttonClass || 's-btn s-btn-outline'" :title="title ?? label" @click="open = !open"><Icon :icon="icon" /><template v-if="label"> {{ label }}</template></button>
        <div v-if="open" class="absolute right-0 z-20 mt-[4px] w-[150px] rounded-[4px] bg-white py-[4px] text-[13px] shadow-lg ring-1 ring-black/5">
            <button v-for="f in props.formats" :key="f" type="button" class="flex w-full items-center gap-[8px] px-[12px] py-[7px] hover:bg-[#f2f7fb]" @click="pick(f)">
                <Icon :icon="OPTIONS[f][1]" :class="OPTIONS[f][2]" /> {{ OPTIONS[f][0] }}
            </button>
        </div>
    </div>
</template>
