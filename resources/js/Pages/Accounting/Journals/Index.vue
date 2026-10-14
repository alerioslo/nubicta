<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import Pager from '../../../Components/Pager.vue';
import EmptyState from '../../../Components/EmptyState.vue';
import ListFilters from '../../../Components/ListFilters.vue';

defineProps({
    entries: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
});
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <Head title="Comprobantes contables" />
    <PageHeader title="Contabilidad" :tabs="tabs" active="Más procesos" />

    <section class="s-card mt-[18px]">
        <ListFilters url="/contabilidad/comprobantes" :filters="filters" placeholder="Buscar por comprobante u observaciones">
            <Link href="/contabilidad/comprobantes/crear" class="s-btn s-btn-primary w-[190px]">Crear comprobante contable</Link>
        </ListFilters>
        <table class="s-table">
            <thead>
                <tr><th>Fecha</th><th>Comprobante</th><th>Observaciones</th><th class="text-right">Movimientos</th><th class="text-right">Débitos</th><th class="text-right">Créditos</th></tr>
            </thead>
            <tbody>
                <tr v-for="e in entries.data" :key="e.id">
                    <td class="whitespace-nowrap">{{ e.date }}</td>
                    <td><Link :href="`/documentos/comprobantes/${e.id}`" class="text-primary">{{ e.name }}</Link></td>
                    <td class="max-w-[380px] truncate">{{ e.observations }}</td>
                    <td class="text-right">{{ e.lines }}</td>
                    <td class="text-right">{{ money(e.debit) }}</td>
                    <td class="text-right" :class="{ 'text-danger': Math.abs(e.debit - e.credit) > 0.5 }">{{ money(e.credit) }}</td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!entries.data.length" />
        <Pager :paginator="entries" />
    </section>
</template>
