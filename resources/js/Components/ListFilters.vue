<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';

/* Buscador + "Más filtros" (fechas y saldo) comun a los listados de documentos. */
const props = defineProps({
    url: { type: String, required: true },
    filters: { type: Object, default: () => ({}) },
    placeholder: { type: String, default: 'Buscar' },
    balance: { type: Boolean, default: false },
    /* Filtros adicionales de lista: [{ key, label, options: [[valor, texto]] }]. */
    selects: { type: Array, default: () => [] },
});

const f = reactive({ q: '', desde: '', hasta: '', saldo: '', ...Object.fromEntries(props.selects.map((s) => [s.key, ''])), ...props.filters });
const open = ref(!!(f.desde || f.hasta || f.saldo || props.selects.some((s) => f[s.key])));
function reload() {
    router.get(props.url, Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
}
let t = null;
function search() {
    clearTimeout(t);
    t = setTimeout(reload, 350);
}
</script>

<template>
    <div class="px-[16px] py-[16px]">
        <div class="flex flex-wrap items-center gap-3">
            <div class="relative w-full sm:w-[300px]">
                <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                <input v-model="f.q" class="s-input pl-9" :placeholder="placeholder" @input="search" />
            </div>
            <button type="button" class="s-btn s-btn-outline" @click="open = !open"><Icon icon="sliders" /> Más filtros</button>
            <div class="ml-auto flex flex-wrap justify-end gap-2"><slot /></div>
        </div>
        <div v-if="open" class="mt-3 flex flex-wrap items-end gap-3">
            <label><span class="s-label">Desde</span><input v-model="f.desde" type="date" class="s-input w-[150px]" @change="reload" /></label>
            <label><span class="s-label">Hasta</span><input v-model="f.hasta" type="date" class="s-input w-[150px]" @change="reload" /></label>
            <label v-if="balance"><span class="s-label">Saldo</span>
                <select v-model="f.saldo" class="s-input w-[170px]" @change="reload"><option value="">Todos</option><option value="pendiente">Con saldo pendiente</option></select>
            </label>
            <label v-for="s in selects" :key="s.key" class="w-full sm:w-auto"><span class="s-label">{{ s.label }}</span>
                <select v-model="f[s.key]" class="s-input w-full sm:w-[230px]" @change="reload"><option value="">Todos</option><option v-for="[v, t] in s.options" :key="v" :value="String(v)">{{ t }}</option></select>
            </label>
        </div>
    </div>
</template>
