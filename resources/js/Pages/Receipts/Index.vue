<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';
import ListFilters from '../../Components/ListFilters.vue';

defineProps({
    receipts: { type: Object, required: true },
    type: { type: String, required: true },
    title: { type: String, required: true },
    tabs: { type: Array, required: true },
    active: { type: String, required: true },
    url: { type: String, required: true },
    filters: { type: Object, default: () => ({}) },
});
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' COP';
</script>

<template>
    <Head :title="active" />
    <PageHeader :title="title" :tabs="tabs" :active="active" />

    <section class="s-card mt-[18px]">
        <ListFilters :url="url" :filters="filters" :placeholder="type === 'RC' ? 'Buscar por cliente o comprobante' : 'Buscar por proveedor o comprobante'" />
        <table class="s-table">
            <thead>
                <tr>
                    <th>Fecha</th><th>Comprobante</th><th>{{ type === 'RC' ? 'Cliente' : 'Proveedor' }}</th>
                    <th>Documentos que paga</th><th>Forma de pago</th><th class="text-right">Valor</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in receipts.data" :key="r.id" :class="{ 'opacity-60': r.annulled }">
                    <td class="whitespace-nowrap">{{ r.date }}</td>
                    <td><Link :href="`/documentos/recibos/${r.id}`" class="text-primary">{{ r.name }}</Link><div class="text-[11px] text-muted">{{ type === 'RC' ? 'Recibo de caja' : 'Recibo de pago' }}<span v-if="r.annulled"> · Anulado</span></div></td>
                    <td class="max-w-[260px]"><div class="truncate text-primary">{{ r.contact }}</div><div class="text-[11px] text-muted">{{ r.identification }}</div></td>
                    <td class="max-w-[220px] truncate">{{ r.applies_to }}</td>
                    <td>{{ r.payment }}</td>
                    <td class="whitespace-nowrap text-right">{{ money(r.total) }}</td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!receipts.data.length" />
        <Pager :paginator="receipts" />
    </section>
</template>
