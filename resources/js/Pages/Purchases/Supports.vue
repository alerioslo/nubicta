<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDialog } from '../../composables/dialog';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';

const props = defineProps({
    rows: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
});

const RANGES = [['rango', 'Rango'], ['todas', 'Todas'], ['hoy', 'Hoy'], ['ayer', 'Ayer'], ['anio', 'Este año'], ['trimestre', 'Este trimestre'], ['mes', 'Este mes'],
    ['semana', 'Esta semana'], ['d7', 'Últimos días: 7'], ['d30', 'Últimos días: 30'], ['d90', 'Últimos días: 90'], ['d365', 'Últimos días: 365']];
const f = reactive({ q: '', desde: '', hasta: '', estado: '', rango: 'rango', ...props.filters });
const criteria = ref(true);
const busy = ref(false);
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
function applyRange() {
    const t = new Date();
    const y = t.getFullYear();
    const days = (n) => [iso(new Date(y, t.getMonth(), t.getDate() - n + 1)), iso(t)];
    const r = {
        todas: ['', ''], hoy: [iso(t), iso(t)], ayer: [iso(new Date(y, t.getMonth(), t.getDate() - 1)), iso(new Date(y, t.getMonth(), t.getDate() - 1))],
        anio: [`${y}-01-01`, `${y}-12-31`], trimestre: [iso(new Date(y, Math.floor(t.getMonth() / 3) * 3, 1)), iso(new Date(y, Math.floor(t.getMonth() / 3) * 3 + 3, 0))],
        mes: [iso(new Date(y, t.getMonth(), 1)), iso(new Date(y, t.getMonth() + 1, 0))],
        semana: [iso(new Date(y, t.getMonth(), t.getDate() - ((t.getDay() + 6) % 7))), iso(new Date(y, t.getMonth(), t.getDate() - ((t.getDay() + 6) % 7) + 6))],
        d7: days(7), d30: days(30), d90: days(90), d365: days(365),
    }[f.rango];
    if (r) [f.desde, f.hasta] = r;
}
const buscar = () => router.get('/compras/soporte-legalizar', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
const limpiar = () => router.get('/compras/soporte-legalizar', { rango: 'todas' }, { replace: true });
const excel = () => (window.location.href = '/compras/soporte-legalizar/excel?' + new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([, v]) => v))));
function upload(file) {
    if (!file) return;
    busy.value = true;
    router.post('/compras/soporte-legalizar', { file }, { forceFormData: true, preserveScroll: true, onFinish: () => (busy.value = false) });
}
const post = async (url, confirmText) => (!confirmText || (await confirmDialog(confirmText))) && router.post(url, {}, { preserveScroll: true });
const cell = (c) => (c && typeof c === 'object' ? c : { text: c ?? '' });
</script>

<template>
    <Head title="Soporte por legalizar" />
    <PageHeader title="Compras y gastos" :tabs="tabs" active="Soporte por legalizar" />

    <h2 class="mt-[14px] text-[26px] text-primary">Soporte de compras o gastos por legalizar</h2>
    <label class="mt-[12px] inline-flex h-[34px] cursor-pointer items-center rounded-[2px] bg-[#6abf40] px-[12px] text-[13px] font-semibold text-white" :class="{ 'opacity-60': busy }">
        Importar nuevo documento
        <input type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.xml,.zip" :disabled="busy" @change="upload($event.target.files[0]); $event.target.value = ''" />
    </label>

    <button type="button" class="mt-[10px] flex items-center gap-[4px] text-[12px] text-ink" @click="criteria = !criteria">
        <Icon :icon="criteria ? 'chevron-up' : 'chevron-down'" class="text-primary" /> {{ criteria ? 'Ocultar criterios de búsqueda' : 'Mostrar criterios de búsqueda' }}
    </button>
    <form v-if="criteria" class="mt-[4px] rounded-[2px] border border-line bg-[#f5f7f8] px-[20px] py-[16px] text-[12px] text-ink" @submit.prevent="buscar">
        <div class="grid grid-cols-2 gap-x-[30px] gap-y-[30px]">
            <label class="grid grid-cols-[80px_1fr] items-center gap-[12px]"><span class="text-right">Documento soporte</span><input v-model="f.q" class="h-[20px] border border-[#ccc] bg-white px-[4px]" /></label>
            <div class="grid grid-cols-[80px_1fr_1fr] items-start gap-[10px]"><span class="text-right">Fecha elaboración</span>
                <select v-model="f.rango" class="h-[20px] border border-[#ccc] bg-white" @change="applyRange"><option v-for="[k, l] in RANGES" :key="k" :value="k">{{ l }}</option></select>
                <div class="space-y-[6px]">
                    <input v-model="f.desde" type="date" class="h-[20px] w-full border border-[#ccc] bg-white px-[4px]" @change="f.rango = 'rango'" />
                    <input v-model="f.hasta" type="date" class="h-[20px] w-full border border-[#ccc] bg-white px-[4px]" @change="f.rango = 'rango'" />
                </div>
            </div>
            <label class="grid grid-cols-[80px_1fr] items-center gap-[12px]"><span class="text-right">Estado</span>
                <select v-model="f.estado" class="h-[20px] border border-[#ccc] bg-white"><option value=""></option><option>Sin causar</option><option>Causado</option></select>
            </label>
        </div>
        <div class="mt-[18px] flex items-center gap-[20px]">
            <button class="h-[26px] rounded-[3px] border border-[#ccc] bg-white px-[10px] font-semibold text-primary">Buscar</button>
            <button type="button" class="font-semibold text-primary" @click="limpiar">Limpiar filtros</button>
        </div>
    </form>

    <section class="mt-[10px] bg-white px-[10px] pb-[16px] pt-[10px] sm:px-[14px]">
        <div class="flex justify-end"><button type="button" class="grid h-[26px] w-[26px] place-items-center rounded-[2px] border border-[#ccc] text-ink" title="Exportar a Excel" @click="excel"><Icon icon="file-excel" /></button></div>
        <div class="s-table-wrap">
            <table class="sticky-actions mt-[10px] w-full text-left text-[12px] text-ink">
                <thead class="text-[13px] text-white">
                    <tr class="bg-[#0275b3]"><th class="px-[6px] py-[6px] font-semibold">Documento soporte</th><th class="font-semibold">Fecha creación</th><th class="font-semibold">Nombre Comprobante</th><th class="font-semibold">Número de comprobante</th><th class="font-semibold">Estado</th><th class="font-semibold">Acción</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in rows.data" :key="i" class="border-b border-line">
                        <td class="px-[6px] py-[6px]"><a :href="cell(r[0]).download" class="text-primary hover:underline">{{ cell(r[0]).text }}</a></td>
                        <td>{{ cell(r[1]).text }}</td>
                        <td>{{ cell(r[2]).text }}</td>
                        <td><Link v-if="cell(r[3]).href" :href="cell(r[3]).href" class="text-primary hover:underline">{{ cell(r[3]).text }}</Link></td>
                        <td>{{ cell(r[4]).text }}</td>
                        <td>
                            <template v-for="a in cell(r[5]).actions ?? []" :key="a.text">
                                <Link v-if="a.href" :href="a.href" class="mr-3 font-semibold text-primary hover:underline">{{ a.text }}</Link>
                                <button v-else type="button" class="mr-3 font-semibold text-[#c62828] hover:underline" @click="post(a.post, a.confirm)">{{ a.text }}</button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-if="!rows.data.length" class="py-[60px] text-center text-[14px] text-muted">No se encontraron resultados para tu búsqueda</p>
        <Pager v-else :paginator="rows" />
    </section>
</template>
