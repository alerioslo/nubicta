<script setup>
import { printInPlace } from '../../composables/print';
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDialog } from '../../composables/dialog';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    docs: { type: Array, default: null },
    classes: { type: Object, required: true },
    types: { type: Array, required: true },
    dianOptions: { type: Array, required: true },
    mailOptions: { type: Array, required: true },
    years: { type: Array, required: true },
    filters: { type: Object, required: true },
    tabs: { type: Array, required: true },
});

const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const f = reactive({ ...props.filters, clases: [...props.filters.clases], tipos: [...props.filters.tipos], dian: [...props.filters.dian] });
const year = ref(Number(f.desde.slice(0, 4)));
const month = ref(Number(f.desde.slice(5, 7)));
const pad = (n) => String(n).padStart(2, '0');
function setMonth() {
    const last = new Date(year.value, month.value, 0).getDate();
    f.desde = `${year.value}-${pad(month.value)}-01`;
    f.hasta = `${year.value}-${pad(month.value)}-${pad(last)}`;
}

const banner = ref(true);
const criteria = ref(true);
const open = ref(null);
const classList = Object.entries(props.classes);
const label = (sel, all, name) => (sel.length ? all.filter((o) => sel.includes(o[0])).map((o) => o[1]).join(', ') : 'Seleccione') || name;
const toggle = (list, v) => (list.includes(v) ? list.splice(list.indexOf(v), 1) : list.push(v));
const toggleAll = (key, values) => (f[key] = f[key].length === values.length ? [] : [...values]);

function buscar() {
    open.value = null;
    router.get('/ventas/envio-masivo', { ...f, buscar: 1 }, { preserveState: true, replace: true });
}
function limpiar() {
    router.get('/ventas/envio-masivo', {}, { replace: true });
}

const perPage = ref(10);
const page = ref(1);
const rows = computed(() => props.docs ?? []);
const pages = computed(() => Math.max(1, Math.ceil(rows.value.length / perPage.value)));
const shown = computed(() => rows.value.slice((page.value - 1) * perPage.value, page.value * perPage.value));
const pageLinks = computed(() => {
    const n = pages.value;
    if (n <= 6) return Array.from({ length: n }, (_, i) => i + 1);
    const p = page.value;
    const set = [...new Set([1, p - 1, p, p + 1, n].filter((x) => x >= 1 && x <= n))].sort((a, b) => a - b);
    return set.flatMap((x, i) => (i && x - set[i - 1] > 1 ? ['…', x] : [x]));
});
watch(() => props.docs, () => { page.value = 1; selected.value = []; });
watch(perPage, () => (page.value = 1));

const selected = ref([]);
const allOnPage = computed(() => shown.value.length > 0 && shown.value.every((d) => selected.value.includes(d.id)));
function togglePage() {
    const ids = shown.value.map((d) => d.id);
    selected.value = allOnPage.value ? selected.value.filter((i) => !ids.includes(i)) : [...new Set([...selected.value, ...ids])];
}
const money = (v) => '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
function imprimir() {
    printInPlace(`/ventas/envio-masivo/imprimir?ids=${selected.value.join(',')}`);
}
async function enviar() {
    if (!await confirmDialog(`Se enviarán ${selected.value.length} comprobantes al correo de cada cliente. ¿Continuar?`)) return;
    router.post('/ventas/envio-masivo/enviar', { ids: selected.value }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Envío masivo" />
    <PageHeader title="Ventas" :tabs="tabs" active="Envío masivo" />

    <div class="mt-[12px]" @click="open = null">
        <div v-if="banner" class="flex items-stretch overflow-hidden rounded-[4px] border border-primary bg-[#e8f4fd] text-[13px] text-ink">
            <div class="grid w-[50px] place-items-center bg-primary text-[16px] text-white"><Icon icon="circle-info" /></div>
            <p class="flex-1 px-[22px] py-[8px] leading-[22px]">
                Para dar cumplimiento al anexo técnico de facturación electrónica de la DIAN vigente, <b>la fecha de elaboración se ajustará por la fecha actual</b> en tus documentos al momento de enviarlos
                para su validación. Esto modificará la contabilización de los documentos asociados.
                <a href="/ayuda#envio-masivo" target="_blank" class="ml-[6px] font-semibold text-primary">Conocer más</a>
            </p>
            <button type="button" class="self-start px-[10px] pt-[4px] text-primary" aria-label="Cerrar" @click="banner = false"><Icon icon="xmark" /></button>
        </div>

        <h2 class="mt-[12px] text-[26px] text-primary">Impresión y envío masivo de comprobantes</h2>
        <p class="mt-[6px] text-[14px] text-ink">Para los documentos electrónicos, la columna <b>“Envío por email”</b> solo se actualizará cuando el documento sea <b>aprobado por la DIAN.</b></p>

        <button type="button" class="mt-[18px] flex items-center gap-[8px] text-[12px] text-ink" @click="criteria = !criteria">
            <Icon :icon="criteria ? 'chevron-up' : 'chevron-down'" class="text-primary" /> {{ criteria ? 'Ocultar criterios de búsqueda' : 'Mostrar criterios de búsqueda' }}
        </button>

        <form v-if="criteria" class="mt-[10px] rounded-[4px] border border-line bg-[#f7f9fa] p-[14px] text-[12px] text-ink" @submit.prevent="buscar">
            <div class="flex gap-[14px]">
                <button class="h-[34px] rounded-[3px] bg-[#6abf40] px-[8px] text-[13px] text-white">Buscar</button>
                <button type="button" class="h-[34px] rounded-[3px] bg-primary px-[8px] text-[13px] text-white" @click="limpiar">Limpiar filtro</button>
            </div>
            <div class="mt-[18px] grid grid-cols-2 gap-x-[40px]">
                <div class="space-y-[22px]">
                    <label class="grid grid-cols-[150px_300px] items-center"><span class="text-right pr-[8px]">Clientes</span>
                        <span class="relative"><input v-model="f.q" class="h-[26px] w-full border border-[#ccc] bg-white px-[4px]" placeholder="Buscar" /><Icon icon="magnifying-glass" class="absolute right-[6px] top-[6px] text-ink" /></span>
                    </label>
                    <div class="grid grid-cols-[150px_150px] items-start"><span class="pt-[4px] text-right pr-[8px]">Comprobante</span>
                        <div class="space-y-[2px]" @click.stop>
                            <div class="relative">
                                <button type="button" class="flex h-[20px] w-full items-center justify-between border border-[#aaa] bg-white px-[4px] text-left" @click="open = open === 'cl' ? null : 'cl'">
                                    <span class="truncate">{{ label(f.clases, classList, 'Comprobante') }}</span><Icon icon="caret-down" />
                                </button>
                                <div v-if="open === 'cl'" class="absolute left-0 top-[22px] z-30 w-[150px] rounded-[4px] bg-white p-[10px] shadow-lg">
                                    <label class="flex gap-[8px] border-b border-line pb-[8px]"><input type="checkbox" :checked="f.clases.length === classList.length" @change="toggleAll('clases', classList.map((c) => c[0]))" /> Seleccionar Todos</label>
                                    <label v-for="[k, l] in classList" :key="k" class="mt-[8px] flex gap-[8px]"><input type="checkbox" :checked="f.clases.includes(k)" @change="toggle(f.clases, k)" /> {{ l }}</label>
                                </div>
                            </div>
                            <div class="relative">
                                <button type="button" class="flex h-[20px] w-full items-center justify-between border border-[#aaa] bg-white px-[4px] text-left" @click="open = open === 'tp' ? null : 'tp'">
                                    <span class="truncate">{{ label(f.tipos, types.map((t) => [t.id, t.name]), 'Tipo') }}</span><Icon icon="caret-down" />
                                </button>
                                <div v-if="open === 'tp'" class="absolute left-0 top-[22px] z-30 w-[150px] rounded-[4px] bg-white p-[10px] shadow-lg">
                                    <label class="flex gap-[8px] border-b border-line pb-[8px]"><input type="checkbox" :checked="f.tipos.length === types.length" @change="toggleAll('tipos', types.map((t) => t.id))" /> Seleccionar Todos</label>
                                    <label v-for="t in types" :key="t.id" class="mt-[8px] flex gap-[8px]"><input type="checkbox" :checked="f.tipos.includes(t.id)" @change="toggle(f.tipos, t.id)" /> {{ t.name }}</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="space-y-[12px]">
                    <div class="grid grid-cols-[130px_1fr] items-start"><span class="pt-[4px] text-right pr-[8px]">Fecha de elaboración</span>
                        <div class="grid grid-cols-[110px_140px_1fr] gap-[4px]">
                            <select v-model.number="year" class="h-[24px] border border-[#ccc] bg-white" @change="setMonth"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                            <select v-model.number="month" class="h-[24px] border border-[#ccc] bg-white" @change="setMonth"><option v-for="(m, i) in MONTHS" :key="m" :value="i + 1">{{ m }}</option></select>
                            <div class="space-y-[4px]">
                                <input v-model="f.desde" type="date" class="h-[24px] w-full border border-[#ccc] bg-white px-[4px]" />
                                <input v-model="f.hasta" type="date" class="h-[24px] w-full border border-[#ccc] bg-white px-[4px]" />
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-[130px_1fr] items-center"><span class="text-right pr-[8px]">Envío a la DIAN</span>
                        <div class="relative" @click.stop>
                            <button type="button" class="flex min-h-[26px] w-full flex-wrap items-center gap-[3px] border border-[#ccc] bg-white px-[6px] py-[2px] text-left" @click="open = open === 'dian' ? null : 'dian'">
                                <span v-for="d in f.dian.slice(0, 3)" :key="d" class="rounded-[2px] bg-[#2a71b8] px-[4px] text-[11px] text-white">{{ d }} <span class="cursor-pointer" @click.stop="toggle(f.dian, d)">x</span></span>
                                <span v-if="!f.dian.length" class="text-muted">Seleccione</span>
                                <span class="ml-auto">{{ f.dian.length > 3 ? `+${f.dian.length - 3}` : '' }} <Icon icon="caret-down" /></span>
                            </button>
                            <div v-if="open === 'dian'" class="absolute left-0 right-0 top-[28px] z-30 rounded-[4px] bg-white p-[10px] shadow-lg">
                                <label class="flex gap-[8px] border-b border-line pb-[8px]"><input type="checkbox" :checked="f.dian.length === dianOptions.length" @change="toggleAll('dian', dianOptions)" /> {{ f.dian.length === dianOptions.length ? 'Quitar todos' : 'Seleccionar todos' }}</label>
                                <label v-for="d in dianOptions" :key="d" class="mt-[8px] flex gap-[8px]"><input type="checkbox" :checked="f.dian.includes(d)" @change="toggle(f.dian, d)" /> {{ d }}</label>
                            </div>
                        </div>
                    </div>
                    <label class="grid grid-cols-[130px_1fr] items-center"><span class="text-right pr-[8px]">Envío por email</span>
                        <select v-model="f.correo" class="h-[26px] border border-[#ccc] bg-white"><option value="">Todos</option><option v-for="m in mailOptions" :key="m">{{ m }}</option></select>
                    </label>
                </div>
            </div>
        </form>

        <div class="mt-[8px] flex justify-end gap-[4px]">
            <button type="button" class="h-[34px] rounded-[2px] bg-[#6abf40] px-[8px] text-[13px] text-white disabled:bg-[#ddd] disabled:text-white" :disabled="!selected.length" @click="imprimir">Imprimir</button>
            <button type="button" class="h-[34px] rounded-[2px] bg-[#6abf40] px-[8px] text-[13px] text-white disabled:bg-[#ddd] disabled:text-white" :disabled="!selected.length || selected.length > 50" @click="enviar">Enviar</button>
        </div>

        <div class="s-table-wrap">
            <table class="mt-[10px] w-full bg-white text-center text-[12px] text-ink">
                <thead class="text-[14px] text-[#555]">
                    <tr class="border-b border-line">
                        <th class="py-[6px] font-normal"><label class="flex items-center justify-center gap-[6px]"><input type="checkbox" class="h-[16px] w-[16px]" :checked="allOnPage" @change="togglePage" /> Tipo transacción</label></th>
                        <th class="font-normal">Comprobante</th><th class="w-[90px] font-normal">Fecha de elaboración</th><th class="font-normal">Identificación</th><th class="font-normal">Sucursal</th>
                        <th class="font-normal">Clientes</th><th class="w-[100px] font-normal">Correo electrónico</th><th class="font-normal">Total</th><th class="font-normal">Envío por email</th><th class="font-normal">Envío a la DIAN</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in shown" :key="d.id" class="border-b border-line">
                        <td class="py-[3px] text-left"><label class="flex items-center gap-[8px] pl-[24px]"><input v-model="selected" type="checkbox" class="h-[14px] w-[14px]" :value="d.id" /> <span class="flex-1 text-center">{{ d.class }}</span></label></td>
                        <td><Link :href="d.url" class="text-primary">{{ d.name }}</Link></td>
                        <td>{{ d.date }}</td><td>{{ d.identification }}</td><td>{{ d.branch }}</td><td>{{ d.contact }}</td>
                        <td class="max-w-[140px] truncate">{{ d.email }}</td><td class="text-right">{{ money(d.total) }}</td><td>{{ d.mail }}</td><td>{{ d.dian }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-[12px] flex items-center text-[11px] text-ink">
            <span>Mostrando <b>{{ rows.length ? (page - 1) * perPage + 1 : 0 }}-{{ Math.min(page * perPage, rows.length) }}</b> de {{ rows.length }}</span>
            <div class="mx-auto flex items-center gap-[6px] text-[13px]">
                <button type="button" class="grid h-[28px] w-[28px] place-items-center text-muted disabled:opacity-40" :disabled="page === 1" aria-label="Anterior" @click="page--"><Icon icon="chevron-left" /></button>
                <template v-if="rows.length">
                    <template v-for="(p, i) in pageLinks" :key="i">
                        <span v-if="p === '…'" class="px-[4px] text-muted">…</span>
                        <button v-else type="button" class="grid h-[28px] min-w-[28px] place-items-center rounded-full" :class="p === page ? 'bg-[#cfe9fb] text-primary' : 'text-primary'" @click="page = p">{{ p }}</button>
                    </template>
                </template>
                <button type="button" class="grid h-[28px] w-[28px] place-items-center text-primary disabled:opacity-40" :disabled="page >= pages" aria-label="Siguiente" @click="page++"><Icon icon="chevron-right" /></button>
            </div>
            <select v-model.number="perPage" class="bg-transparent text-[13px] text-primary"><option v-for="n in [10, 20, 50, 100]" :key="n" :value="n">{{ n }} por página</option></select>
        </div>
    </div>
</template>
