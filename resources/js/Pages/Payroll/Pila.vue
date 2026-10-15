<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import DownloadMenu from '../../Components/DownloadMenu.vue';

/* Resumen de aportes del mes para diligenciar la planilla PILA en el operador. */
const props = defineProps({ tabs: Array, report: Object });
const month = ref(props.report.month);
const money = (v) => Number(v ?? 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });
const consult = () => router.get('/nomina/pila', { mes: month.value });
const download = (f) => (window.location.href = `/nomina/pila/descargar?mes=${props.report.month}&formato=${f}`);
</script>

<template>
    <Head title="Planilla de aportes" />
    <PageHeader title="Nómina" :tabs="tabs" active="Más procesos" />
    <h2 class="mt-[24px] px-[22px] text-[22px] font-semibold text-primary">Liquidación planilla integrada de aportes (PILA)</h2>

    <section class="s-card mx-[10px] mt-[14px] flex flex-wrap items-end gap-3 p-4 text-[12px]">
        <label class="flex flex-col gap-1">Periodo de cotización <input v-model="month" type="month" class="s-input h-[32px]" /></label>
        <button type="button" class="s-btn s-btn-primary" @click="consult">Consultar</button>
        <DownloadMenu @download="download" />
        <p class="w-full text-[11.5px] text-muted">Se toma de las nóminas del ERP contabilizadas en el mes. Con estos valores diligencias la planilla en el operador PILA (Aportes en Línea, SOI, Mi Planilla...).</p>
    </section>

    <section class="s-card mx-[10px] mt-[12px] overflow-x-auto p-[16px]">
        <table class="s-table w-full whitespace-nowrap text-[12px]">
            <thead>
                <tr>
                    <th>Empleado</th><th class="text-right">Días</th><th class="text-right">IBC</th><th>EPS</th><th class="text-right">Salud</th><th>AFP</th>
                    <th class="text-right">Pensión</th><th class="text-right">FSP</th><th>ARL</th><th class="text-right">ARL</th><th>CCF</th><th class="text-right">CCF</th>
                    <th class="text-right">SENA</th><th class="text-right">ICBF</th><th>Novedades</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in report.rows" :key="r.identification">
                    <td><div class="font-semibold">{{ r.name }}</div><div class="text-[11px] text-muted">{{ r.identification }}</div></td>
                    <td class="text-right">{{ r.days }}</td><td class="text-right">{{ money(r.ibc) }}</td>
                    <td class="max-w-[140px] truncate" :title="r.eps">{{ r.eps }}</td><td class="text-right">{{ money(r.health) }}</td>
                    <td class="max-w-[140px] truncate" :title="r.afp">{{ r.afp }}</td><td class="text-right">{{ money(r.pension) }}</td><td class="text-right">{{ money(r.fsp) }}</td>
                    <td class="max-w-[140px] truncate" :title="r.arl">{{ r.arl }} ({{ r.arl_class }})</td><td class="text-right">{{ money(r.arl_value) }}</td>
                    <td class="max-w-[140px] truncate" :title="r.ccf">{{ r.ccf }}</td><td class="text-right">{{ money(r.ccf_value) }}</td>
                    <td class="text-right">{{ money(r.sena) }}</td><td class="text-right">{{ money(r.icbf) }}</td><td>{{ r.novelties }}</td>
                </tr>
                <tr v-if="!report.rows.length"><td colspan="15" class="py-6 text-center text-muted">No hay nóminas del ERP contabilizadas en este periodo.</td></tr>
                <tr v-else class="font-bold">
                    <td colspan="4">Totales</td><td class="text-right">{{ money(report.totals.health) }}</td><td></td><td class="text-right">{{ money(report.totals.pension) }}</td>
                    <td class="text-right">{{ money(report.totals.fsp) }}</td><td></td><td class="text-right">{{ money(report.totals.arl_value) }}</td><td></td>
                    <td class="text-right">{{ money(report.totals.ccf_value) }}</td><td class="text-right">{{ money(report.totals.sena) }}</td><td class="text-right">{{ money(report.totals.icbf) }}</td>
                    <td>Total $ {{ money(report.totals.total) }}</td>
                </tr>
            </tbody>
        </table>
    </section>
</template>
