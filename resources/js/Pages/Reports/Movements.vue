<script setup>
import DownloadMenu from '../../Components/DownloadMenu.vue';
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';

const props = defineProps({
    rows: { type: [Object, Array], required: true },
    view: { type: String, required: true },
    totals: { type: Object, required: true },
    filters: { type: Object, required: true },
    lines: { type: Array, required: true },
    sellers: { type: Array, required: true },
    ivaOptions: { type: Array, required: true },
});

const f = reactive({
    desde: props.filters.from,
    hasta: props.filters.to,
    categoria: props.filters.categoria ?? '',
    iva: props.filters.iva ?? '',
    vendedor: props.filters.vendedor ?? '',
    tipo: props.filters.tipo ?? 'FV',
    q: props.filters.q ?? '',
});
const params = (extra = {}) => Object.fromEntries(Object.entries({ ...f, vista: props.view, ...extra }).filter(([, v]) => v !== '' && v != null));
function reload(extra = {}) {
    router.get('/movimientos', params(extra), { preserveState: true, replace: true });
}
let t = null;
function search() {
    clearTimeout(t);
    t = setTimeout(() => reload(), 400);
}
function setMonth(offset) {
    const d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() + offset);
    const end = offset === 0 ? new Date() : new Date(d.getFullYear(), d.getMonth() + 1, 0);
    const iso = (x) => `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`;
    f.desde = iso(d);
    f.hasta = iso(end);
    reload();
}
const exportUrl = computed(() => '/movimientos/excel?' + new URLSearchParams(params()).toString());
const list = computed(() => (Array.isArray(props.rows) ? props.rows : props.rows.data));
const money = (v) => Number(v ?? 0).toLocaleString('es-CO', { maximumFractionDigits: 2 });
const qty = (v) => Number(v ?? 0).toLocaleString('es-CO', { maximumFractionDigits: 2 });
const date = (d) => d.split('-').reverse().join('/');
const tabs = [
    { key: 'detalle', label: 'Productos vendidos' },
    { key: 'categoria', label: 'Resumen por categoría' },
    { key: 'producto', label: 'Resumen por producto' },
    { key: 'vendedor', label: 'Resumen por vendedor' },
];
const download = (base, f) => (window.location.href = base + (base.includes('?') ? '&' : '?') + 'formato=' + f);
</script>

<template>
    <Head title="Movimientos del mes" />
    <PageHeader title="Movimientos del mes" :tabs="tabs.map((t) => t.label)" :active="tabs.find((t) => t.key === view).label"
        @tab="(label) => reload({ vista: tabs.find((t) => t.label === label).key, page: undefined })" />

    <section class="s-card mt-[18px] p-4">
        <div class="flex flex-wrap items-end gap-3">
            <label><span class="s-label">Desde</span><input v-model="f.desde" type="date" class="s-input w-[150px]" @change="reload()" /></label>
            <label><span class="s-label">Hasta</span><input v-model="f.hasta" type="date" class="s-input w-[150px]" @change="reload()" /></label>
            <div class="flex gap-1">
                <button type="button" class="s-btn s-btn-outline" @click="setMonth(0)">Este mes</button>
                <button type="button" class="s-btn s-btn-outline" @click="setMonth(-1)">Mes anterior</button>
            </div>
            <label><span class="s-label">Categoría</span>
                <select v-model="f.categoria" class="s-input w-[190px]" @change="reload()">
                    <option value="">Todas</option>
                    <option v-for="l in lines" :key="l.id" :value="l.id">({{ l.code }}) {{ l.name }}</option>
                    <option value="ninguna">Desconocida</option>
                </select>
            </label>
            <label><span class="s-label">IVA</span>
                <select v-model="f.iva" class="s-input w-[110px]" @change="reload()">
                    <option value="">Todos</option>
                    <option v-for="v in ivaOptions" :key="v" :value="v">{{ v }}%</option>
                    <option value="ninguno">Sin IVA</option>
                </select>
            </label>
            <label><span class="s-label">Vendedor</span>
                <select v-model="f.vendedor" class="s-input w-[180px]" @change="reload()">
                    <option value="">Todos</option>
                    <option v-for="s in sellers" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
            </label>
            <label><span class="s-label">Documento</span>
                <select v-model="f.tipo" class="s-input w-[150px]" @change="reload()">
                    <option value="FV">Facturas</option>
                    <option value="NC">Notas crédito</option>
                    <option value="todos">Facturas - notas</option>
                </select>
            </label>
            <label class="relative w-[240px]"><span class="s-label">Buscar</span>
                <Icon icon="magnifying-glass" class="absolute left-3 top-[30px] text-muted" />
                <input v-model="f.q" class="s-input pl-9" placeholder="Producto, factura o cliente" @input="search" />
            </label>
            <DownloadMenu class="ml-auto" @download="(f) => download(exportUrl, f)" />
        </div>
    </section>

    <div class="mt-[14px] grid grid-cols-4 gap-[14px]">
        <div class="s-card p-4"><p class="text-muted">Documentos</p><p class="text-[20px] font-bold text-ink">{{ qty(totals.documents) }}</p></div>
        <div class="s-card p-4"><p class="text-muted">Líneas</p><p class="text-[20px] font-bold text-ink">{{ qty(totals.n_lines) }}</p></div>
        <div class="s-card p-4"><p class="text-muted">Unidades</p><p class="text-[20px] font-bold text-ink">{{ qty(totals.quantity) }}</p></div>
        <div class="s-card p-4"><p class="text-muted">Total cobrado</p><p class="text-[20px] font-bold text-primary">$ {{ money(totals.total) }}</p></div>
    </div>

    <section class="s-card mt-[14px] overflow-x-auto">
        <table v-if="view === 'detalle'" class="s-table">
            <thead>
                <tr>
                    <th>Código</th><th>N° factura</th><th>Fecha</th><th>Tipo</th><th>Producto</th><th>Cliente</th>
                    <th class="text-right">Precio</th><th class="text-right">Cantidad</th><th class="text-right">Cobrado</th>
                    <th>Vendedor</th><th>Categoría</th><th class="text-right">IVA</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in list" :key="r.id" :class="{ 'text-danger': r.sign < 0 }">
                    <td>{{ r.code }}</td>
                    <td class="whitespace-nowrap">{{ r.document }}</td>
                    <td class="whitespace-nowrap">{{ date(r.date) }}</td>
                    <td>{{ r.type === 'NC' ? 'Nota crédito' : 'Factura' }}</td>
                    <td>{{ r.product }}</td>
                    <td>{{ r.customer }}<div class="text-[11px] text-muted">{{ r.identification }}</div></td>
                    <td class="text-right">{{ money(r.price) }}</td>
                    <td class="text-right">{{ qty(r.quantity * r.sign) }}</td>
                    <td class="text-right">{{ money(r.total * r.sign) }}</td>
                    <td>{{ r.seller ?? '' }}</td>
                    <td>{{ r.line ?? 'Desconocida' }}</td>
                    <td class="text-right">{{ r.tax_percentage === null ? 'Desconocido' : Number(r.tax_percentage) + '%' }}</td>
                </tr>
            </tbody>
        </table>
        <div v-else class="s-table-wrap">
            <table class="s-table">
                <thead>
                    <tr>
                        <th v-if="view === 'producto'">Código</th>
                        <th>{{ { categoria: 'Categoría', producto: 'Producto', vendedor: 'Vendedor' }[view] }}</th>
                        <th v-if="view === 'producto'">Categoría</th>
                        <th v-if="view === 'categoria'" class="text-right">Productos distintos</th>
                        <th v-if="view === 'vendedor'" class="text-right">Documentos</th>
                        <th class="text-right">Cantidad</th>
                        <th class="text-right">Cobrado</th>
                        <th class="text-right">% del total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(r, i) in list" :key="i">
                        <td v-if="view === 'producto'">{{ r.code }}</td>
                        <td class="font-semibold text-ink">{{ r.label }}</td>
                        <td v-if="view === 'producto'">{{ r.line }}</td>
                        <td v-if="view === 'categoria'" class="text-right">{{ r.products }}</td>
                        <td v-if="view === 'vendedor'" class="text-right">{{ r.documents }}</td>
                        <td class="text-right">{{ qty(r.quantity) }}</td>
                        <td class="text-right">$ {{ money(r.total) }}</td>
                        <td class="text-right">{{ totals.total ? ((r.total / totals.total) * 100).toFixed(1) : 0 }}%</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!list.length" title="Sin movimientos" text="No hay ventas con los filtros seleccionados." />
        <Pager v-if="!Array.isArray(rows)" :paginator="rows" />
    </section>
</template>
