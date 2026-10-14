<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';

const props = defineProps({
    documents: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
});

const f = reactive({ q: '', tipo: '', desde: '', hasta: '', saldo: '', ...props.filters });
const showFilters = ref(!!(f.tipo || f.desde || f.hasta || f.saldo));
function reload() {
    router.get('/ventas', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
}
let t = null;
function search() {
    clearTimeout(t);
    t = setTimeout(reload, 350);
}
const money = (v, c) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + c;
const dian = (s) => ({ Accepted: ['Aprobada', 'text-ok', 'circle-check'], Rejected: ['Rechazada', 'text-danger', 'triangle-exclamation'] })[s] ?? [s ? 'Pendiente' : 'Sin enviar', 'text-muted', 'clock-rotate-left'];
const mail = (s) => ({ sent: ['Enviado', 's-badge-ok'], not_sent: ['Sin enviar', 's-badge-warn'], error: ['Fallo de envío', 's-badge-danger'] })[s] ?? ['Sin enviar', 's-badge-warn'];
const pagination = computed(() => props.documents);
</script>

<template>
    <Head title="Ventas" />
    <PageHeader title="Ventas" :tabs="tabs" active="Ventas" />

    <section class="s-card mt-[18px]">
        <div class="flex flex-wrap items-center gap-3 px-[16px] py-[16px]">
            <div class="relative w-[300px]">
                <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                <input v-model="f.q" class="s-input pl-9" placeholder="Buscar por Identificación o Cliente" @input="search" />
            </div>
            <button type="button" class="s-btn s-btn-outline" @click="showFilters = !showFilters"><Icon icon="sliders" /> Más filtros</button>
            <div class="ml-auto flex gap-2">
                <Link href="/movimientos" class="s-btn s-btn-outline">Ver reportes</Link>
                <Link href="/ventas/facturas/crear" class="s-btn s-btn-primary w-[130px]">Crear factura</Link>
            </div>
        </div>
        <div v-if="showFilters" class="flex flex-wrap items-end gap-3 px-[16px] pb-[16px]">
            <label><span class="s-label">Tipo</span>
                <select v-model="f.tipo" class="s-input w-[160px]" @change="reload">
                    <option value="">Todos</option><option value="FV">Facturas de venta</option><option value="NC">Notas crédito</option><option value="ND">Notas débito</option>
                </select>
            </label>
            <label><span class="s-label">Desde</span><input v-model="f.desde" type="date" class="s-input w-[150px]" @change="reload" /></label>
            <label><span class="s-label">Hasta</span><input v-model="f.hasta" type="date" class="s-input w-[150px]" @change="reload" /></label>
            <label><span class="s-label">Saldo</span>
                <select v-model="f.saldo" class="s-input w-[150px]" @change="reload"><option value="">Todos</option><option value="pendiente">Con saldo pendiente</option></select>
            </label>
        </div>

        <table class="s-table">
            <thead>
                <tr>
                    <th>Fecha</th><th>Comprobante</th><th>Cliente</th><th class="text-right">Total</th><th class="text-right">Saldo</th>
                    <th>DIAN</th><th>Correo</th><th class="w-[160px]">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="d in documents.data" :key="d.id" :class="{ 'opacity-60': d.annulled }">
                    <td class="whitespace-nowrap">{{ d.date }}</td>
                    <td><Link :href="`/documentos/ventas/${d.id}`" class="text-primary">{{ d.name }}</Link><div class="text-[11px] text-muted">{{ d.type }}<span v-if="d.annulled"> · Anulada</span></div></td>
                    <td class="max-w-[240px]"><div class="truncate text-primary">{{ d.customer }}</div><div class="text-[11px] text-muted">{{ d.identification }}</div></td>
                    <td class="whitespace-nowrap text-right">{{ money(d.total, d.currency) }}</td>
                    <td class="text-right">
                        <div>{{ money(d.balance, '').trim() }}</div>
                        <span v-if="d.is_invoice" class="s-badge" :class="d.balance > 0 ? 's-badge-warn' : 's-badge-ok'">{{ d.balance > 0 ? 'Pendiente' : 'Pagada' }}</span>
                    </td>
                    <td class="whitespace-nowrap" :class="dian(d.dian_status)[1]"><Icon :icon="dian(d.dian_status)[2]" /> <span class="text-ink">{{ dian(d.dian_status)[0] }}</span></td>
                    <td><span class="s-badge" :class="mail(d.mail_status)[1]">{{ mail(d.mail_status)[0] }}</span></td>
                    <td>
                        <a v-if="d.public_url" :href="d.public_url" target="_blank" rel="noopener" class="s-btn s-btn-outline h-[28px] w-full">Ver documento</a>
                    </td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!documents.data.length" />
        <Pager :paginator="pagination" />
    </section>
</template>
