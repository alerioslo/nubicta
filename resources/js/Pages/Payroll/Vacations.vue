<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

/* Libro de vacaciones: 15 dias habiles por cada 360 dias trabajados, menos los disfrutados. */
defineProps({ tabs: Array, rows: Array });
const money = (v) => Number(v ?? 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });
</script>

<template>
    <Head title="Libro de vacaciones" />
    <PageHeader title="Nómina" :tabs="tabs" active="Más procesos" />
    <h2 class="mt-[24px] px-[22px] text-[22px] font-semibold text-primary">Libro de vacaciones</h2>
    <p class="px-[22px] text-[12px] text-muted">Los días disfrutados antes del ERP se registran en el empleado (Contrato y nómina › Días de vacaciones ya disfrutados).</p>

    <section class="s-card mx-[10px] mt-[14px] p-[16px]">
        <table class="s-table w-full text-[12.5px]">
            <thead>
                <tr>
                    <th>Empleado</th><th>Ingreso</th><th>Retiro</th><th class="text-right">Días trabajados</th><th class="text-right">Días causados</th>
                    <th class="text-right">Disfrutados</th><th class="text-right">Pendientes</th><th class="text-right">Valor pendiente</th><th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.id" :class="!r.active && 'text-muted'">
                    <td><div class="font-semibold">{{ r.name }}</div><div class="text-[11px] text-muted">{{ r.identification }}{{ r.active ? '' : ' · retirado' }}</div></td>
                    <td>{{ r.start }}</td><td>{{ r.end || '—' }}</td>
                    <td class="text-right">{{ r.worked_days }}</td><td class="text-right">{{ r.earned }}</td><td class="text-right">{{ r.taken }}</td>
                    <td class="text-right font-semibold" :class="r.pending < 0 && 'text-[#c62828]'">{{ r.pending }}</td>
                    <td class="text-right">{{ money(r.value) }}</td>
                    <td><Link :href="`/nomina/empleados/${r.id}/editar`" class="s-btn-link"><Icon icon="pen" /></Link></td>
                </tr>
                <tr v-if="!rows.length"><td colspan="9" class="py-6 text-center text-muted">No hay empleados con fecha de ingreso.</td></tr>
            </tbody>
        </table>
    </section>
</template>
