<script setup>
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import DocSearch from '../../Components/Forms/DocSearch.vue';

/* Informe generico del catalogo de Siigo: filtros segun la definicion, tabla con formatos, totales y Excel. */
const props = defineProps({ slug: String, report: Object, rows: Object, totals: Object, filters: Object, options: Object });

const has = (k) => props.report.filters.includes(k);
const f = reactive({
    desde: props.filters.from, hasta: props.filters.to, corte: props.filters.cutoff, anio: props.filters.year,
    tercero: props.filters.contact, tercero_label: props.filters.contact_label ? `${props.filters.contact_label.identification} - ${props.filters.contact_label.name}` : '',
    producto: props.filters.product, producto_label: props.filters.product_label ? `${props.filters.product_label.code} - ${props.filters.product_label.name}` : '',
    vendedor: props.filters.seller ?? '', linea: props.filters.line ?? '', modulo: props.filters.module, nivel: props.filters.level,
    cuenta: props.filters.account ?? '', tipo: props.filters.doctype ?? '', dian: props.filters.dian ?? '', q: props.filters.q ?? '',
});

const data = computed(() => props.rows.data ?? []);
const columns = computed(() => Object.entries(props.report.columns));
const isPaginator = computed(() => props.rows.last_page !== undefined);

function params() {
    const p = {};
    if (has('range')) Object.assign(p, { desde: f.desde, hasta: f.hasta });
    if (has('cutoff')) p.corte = f.corte;
    if (has('year')) p.anio = f.anio;
    if (has('contact') && f.tercero) p.tercero = f.tercero;
    if (has('product') && f.producto) p.producto = f.producto;
    if (has('seller') && f.vendedor) p.vendedor = f.vendedor;
    if (has('line') && f.linea) p.linea = f.linea;
    if (has('module')) p.modulo = f.modulo;
    if (has('level')) p.nivel = f.nivel;
    if (has('account') && f.cuenta) p.cuenta = f.cuenta;
    if (has('doctype') && f.tipo) p.tipo = f.tipo;
    if (has('dian') && f.dian) p.dian = f.dian;
    if (has('q') && f.q) p.q = f.q;
    return p;
}

const generate = () => router.get(`/reportes/${props.slug}`, params(), { preserveScroll: true });
const download = () => (window.location.href = `/reportes/${props.slug}?${new URLSearchParams({ ...params(), export: 1 })}`);

const num = (v, d = 2) => (Math.abs(Number(v)) < 0.005 ? 0 : Number(v)).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
function fmt(v, type) {
    if (v === null || v === undefined || v === '') return type === 'money' ? '' : '';
    if (type === 'money') return num(v);
    if (type === 'qty') return num(v, 2);
    if (type === 'pct') return `${num(v)} %`;
    if (type === 'int') return Number(v).toLocaleString('en-US');
    if (type === 'date') return String(v).slice(0, 10).split('-').reverse().join('/');
    return v;
}
const numeric = (t) => ['money', 'qty', 'pct', 'int'].includes(t);
</script>

<template>
    <Head :title="report.title" />
    <PageHeader :title="report.title" back="/reportes" />
    <p v-if="report.note" class="mt-[8px] text-[12px] text-muted"><Icon icon="circle-info" class="text-primary" /> {{ report.note }}</p>

    <section class="s-card mt-[16px] px-[20px] py-[16px] text-[13px]">
        <div class="grid grid-cols-4 gap-x-[16px] gap-y-[12px]">
            <template v-if="has('module')">
                <label class="block"><span class="s-label">Tipo de cartera</span>
                    <select v-model="f.modulo" class="s-input w-full"><option value="ventas">Cuentas por cobrar (clientes)</option><option value="compras">Cuentas por pagar (proveedores)</option></select></label>
            </template>
            <template v-if="has('range')">
                <label class="block"><span class="s-label">Fecha inicial</span><input v-model="f.desde" type="date" class="s-input w-full" /></label>
                <label class="block"><span class="s-label">Fecha final</span><input v-model="f.hasta" type="date" class="s-input w-full" /></label>
            </template>
            <label v-if="has('cutoff')" class="block"><span class="s-label">Fecha de corte</span><input v-model="f.corte" type="date" class="s-input w-full" /></label>
            <label v-if="has('year')" class="block"><span class="s-label">Año</span>
                <select v-model="f.anio" class="s-input w-full"><option v-for="y in options.years" :key="y" :value="y">{{ y }}</option></select></label>
            <div v-if="has('contact')"><span class="s-label">Tercero</span>
                <DocSearch v-model="f.tercero" v-model:label="f.tercero_label" url="/api/buscar/terceros" input-class="s-input w-full" placeholder="Todos" /></div>
            <div v-if="has('product')"><span class="s-label">Producto</span>
                <DocSearch v-model="f.producto" v-model:label="f.producto_label" url="/api/buscar/productos" input-class="s-input w-full" placeholder="Todos" /></div>
            <label v-if="has('seller')" class="block"><span class="s-label">Vendedor</span>
                <select v-model="f.vendedor" class="s-input w-full"><option value="">Todos</option><option v-for="s in options.sellers" :key="s.id" :value="s.id">{{ s.name }}</option></select></label>
            <label v-if="has('line')" class="block"><span class="s-label">Línea de producto</span>
                <select v-model="f.linea" class="s-input w-full"><option value="">Todas</option><option v-for="l in options.lines" :key="l.id" :value="l.id">{{ l.code }} - {{ l.name }}</option></select></label>
            <label v-if="has('level')" class="block"><span class="s-label">Nivel de cuenta</span>
                <select v-model="f.nivel" class="s-input w-full"><option v-for="(n, i) in ['Clase', 'Grupo', 'Cuenta', 'Subcuenta', 'Auxiliar']" :key="n" :value="i + 1">{{ n }}</option></select></label>
            <label v-if="has('account')" class="block"><span class="s-label">Cuenta (inicia con)</span><input v-model="f.cuenta" class="s-input w-full" placeholder="Ej: 1105" /></label>
            <label v-if="has('doctype')" class="block"><span class="s-label">Tipo de comprobante</span>
                <select v-model="f.tipo" class="s-input w-full"><option value="">Todos</option><option v-for="t in options.doctypes" :key="t" :value="t">{{ t }}</option></select></label>
            <label v-if="has('dian')" class="block"><span class="s-label">Estado DIAN</span>
                <select v-model="f.dian" class="s-input w-full"><option value="">Todos</option><option value="Accepted">Aceptado</option><option value="Rejected">Rechazado</option><option value="Pending">Pendiente</option></select></label>
            <label v-if="has('q')" class="col-span-2 block"><span class="s-label">Buscar</span><input v-model="f.q" class="s-input w-full" placeholder="Nombre, identificación o correo" @keyup.enter="generate" /></label>
        </div>
        <div class="mt-[14px] flex justify-end gap-[10px]">
            <button type="button" class="s-btn s-btn-outline" @click="download"><Icon icon="file-excel" /> Descargar Excel</button>
            <button type="button" class="s-btn s-btn-primary" @click="generate">Generar reporte</button>
        </div>
    </section>

    <section class="s-card mt-[14px] overflow-hidden">
        <div class="overflow-x-auto">
            <table class="s-table text-[12.5px]">
                <thead>
                    <tr><th v-for="[k, c] in columns" :key="k" :class="{ 'text-right': numeric(c[1]) }" class="whitespace-nowrap">{{ c[0] }}</th></tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in data" :key="i" :class="{ 'font-bold': r.bold }">
                        <td v-for="[k, c] in columns" :key="k" :class="{ 'text-right whitespace-nowrap': numeric(c[1]) }">{{ fmt(r[k], c[1]) }}</td>
                    </tr>
                    <tr v-if="!data.length"><td :colspan="columns.length" class="py-[30px] text-center text-muted">No hay información para los filtros seleccionados</td></tr>
                </tbody>
                <tfoot v-if="totals && data.length">
                    <tr class="bg-th font-bold">
                        <td v-for="([k, c], idx) in columns" :key="k" class="whitespace-nowrap px-[14px] py-[10px]" :class="{ 'text-right': numeric(c[1]) }">
                            {{ report.totals.includes(k) ? fmt(totals[k], c[1]) : idx === 0 ? 'Total' : '' }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <Pager v-if="isPaginator" :paginator="rows" />
    </section>
</template>
