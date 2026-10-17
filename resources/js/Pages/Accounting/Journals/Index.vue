<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import Pager from '../../../Components/Pager.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import ListFilters from '../../../Components/ListFilters.vue';

/* Listado de comprobantes contables (Siigo solo los deja buscar): tipo, tercero, totales y estado, con filtros por fecha, tipo y estado. */
const props = defineProps({
    entries: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    types: { type: Array, default: () => [] },
});
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const selects = [
    { key: 'tipo', label: 'Tipo de comprobante', options: props.types },
    { key: 'estado', label: 'Estado', options: [['activo', 'Activos'], ['anulado', 'Anulados']] },
];
const open = (e) => router.visit(`/documentos/comprobantes/${e.id}`);
</script>

<template>
    <Head title="Comprobantes contables" />
    <PageHeader title="Contabilidad" :tabs="tabs" active="Comprobantes contables" />

    <section class="s-card mt-[18px]">
        <ListFilters url="/contabilidad/comprobantes" :filters="filters" :selects="selects" placeholder="Buscar por comprobante, tercero u observaciones">
            <Link href="/contabilidad/comprobantes-favoritos" class="s-btn s-btn-outline">Favoritos</Link>
            <Link href="/contabilidad/comprobantes/crear" class="s-btn s-btn-primary w-[190px]">Crear comprobante contable</Link>
        </ListFilters>
        <div class="s-table-wrap">
            <table class="s-table">
                <thead>
                    <tr><th>Fecha</th><th>Comprobante</th><th>Tipo</th><th>Tercero</th><th>Observaciones</th><th class="text-right">Débitos</th><th class="text-right">Créditos</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <tr v-for="e in entries.data" :key="e.id" class="cursor-pointer hover:bg-[#f5f9fc]" :class="{ 'text-muted': e.annulled }" @click="open(e)">
                        <td class="whitespace-nowrap">{{ e.date }}</td>
                        <td class="whitespace-nowrap"><Link :href="`/documentos/comprobantes/${e.id}`" class="text-primary" @click.stop>{{ e.name }}</Link></td>
                        <td class="max-w-[180px] truncate">{{ e.type }}</td>
                        <td class="max-w-[220px] truncate" :title="e.identification">{{ e.contact }}</td>
                        <td class="max-w-[300px] truncate" :title="e.observations">{{ e.observations }}</td>
                        <td class="text-right">{{ money(e.debit) }}</td>
                        <td class="text-right" :class="{ 'text-danger': !e.annulled && Math.abs(e.debit - e.credit) > 0.5 }">{{ money(e.credit) }}</td>
                        <td><span v-if="e.annulled" class="rounded-[10px] bg-[#ec407a] px-[8px] py-[1px] text-[10px] font-semibold text-white">Anulado</span><span v-else class="text-[11px] text-[#43a047]">Contabilizado</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!entries.data.length" />
        <Pager :paginator="entries" />
    </section>
</template>
