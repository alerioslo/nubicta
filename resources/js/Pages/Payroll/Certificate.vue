<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

/* Certificado de ingresos y retenciones por rentas de trabajo (formulario 220) a partir de la contabilidad (Siigo + ERP). */
const props = defineProps({ tabs: Array, year: Number, employees: Array, employee: Number, data: Object, company: Object });
const filters = reactive({ empleado: props.employee, anio: props.year });
const money = (v) => Number(v ?? 0).toLocaleString('es-CO', { maximumFractionDigits: 0 });
const consult = () => router.get('/nomina/certificado-ingresos', filters);
const print = () => window.print();
</script>

<template>
    <Head title="Certificado de ingresos y retenciones" />
    <div class="no-print">
        <PageHeader title="Nómina" :tabs="tabs" active="Más procesos" />
        <h2 class="mt-[24px] px-[22px] text-[22px] font-semibold text-primary">Certificado de ingresos y retenciones · Formulario 220</h2>
        <section class="s-card md:mx-[10px] mt-[14px] flex flex-wrap items-end gap-3 p-4 text-[12px]">
            <label class="flex flex-col gap-1">Empleado
                <select v-model="filters.empleado" class="s-input h-[32px] min-w-[320px]">
                    <option :value="null">Selecciona...</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.identification }} - {{ e.name }}</option>
                </select>
            </label>
            <label class="flex flex-col gap-1">Año gravable <input v-model="filters.anio" type="number" min="2015" class="s-input h-[32px] w-[100px]" /></label>
            <button type="button" class="s-btn s-btn-primary" :disabled="!filters.empleado" @click="consult">Generar</button>
            <button v-if="data" type="button" class="s-btn s-btn-outline" @click="print"><Icon icon="print" /> Imprimir / PDF</button>
        </section>
    </div>

    <div v-if="data" class="sheet md:mx-[10px] mt-[12px] max-w-[860px] bg-white p-8 text-[12px] shadow">
        <h3 class="text-center text-[14px] font-bold">CERTIFICADO DE INGRESOS Y RETENCIONES POR RENTAS DE TRABAJO Y DE PENSIONES</h3>
        <p class="text-center">Año gravable {{ data.year }} · Formulario 220</p>

        <div class="s-table-wrap">
            <table class="mt-4 w-full border border-[#999]">
                <tr class="bg-[#eef1f4]"><td colspan="2" class="px-2 py-1 font-bold">Retenedor</td></tr>
                <tr><td class="w-[200px] px-2">NIT</td><td class="px-2">{{ company.nit }}</td></tr>
                <tr><td class="px-2">Razón social</td><td class="px-2">{{ company.name }}</td></tr>
                <tr class="bg-[#eef1f4]"><td colspan="2" class="px-2 py-1 font-bold">Trabajador</td></tr>
                <tr><td class="px-2">Número de identificación</td><td class="px-2">{{ data.employee.identification }}</td></tr>
                <tr><td class="px-2">Apellidos y nombres</td><td class="px-2">{{ data.employee.name }}</td></tr>
                <tr><td class="px-2">Periodo certificado</td><td class="px-2">01/01/{{ data.year }} a 31/12/{{ data.year }}</td></tr>
                <tr><td class="px-2">Lugar donde se practicó la retención</td><td class="px-2">{{ company.city }}</td></tr>
            </table>
        </div>

        <div class="s-table-wrap">
            <table class="mt-3 w-full border border-[#999]">
                <tr class="bg-[#eef1f4]"><td class="px-2 py-1 font-bold">Concepto de los ingresos</td><td class="w-[180px] px-2 text-right font-bold">Valor</td></tr>
                <tr><td class="px-2">Pagos por salarios</td><td class="px-2 text-right">{{ money(data.salaries) }}</td></tr>
                <tr><td class="px-2">Otros pagos laborales (auxilio de transporte, bonificaciones, horas extras)</td><td class="px-2 text-right">{{ money(data.other) }}</td></tr>
                <tr><td class="px-2">Prestaciones sociales (prima, vacaciones e indemnizaciones)</td><td class="px-2 text-right">{{ money(data.benefits) }}</td></tr>
                <tr><td class="px-2">Cesantías e intereses de cesantías efectivamente pagadas o consignadas</td><td class="px-2 text-right">{{ money(data.severance) }}</td></tr>
                <tr class="font-bold"><td class="px-2">Total de ingresos brutos</td><td class="px-2 text-right">{{ money(data.gross) }}</td></tr>
                <tr class="bg-[#eef1f4]"><td class="px-2 py-1 font-bold">Concepto de los aportes</td><td></td></tr>
                <tr><td class="px-2">Aportes obligatorios por salud a cargo del trabajador</td><td class="px-2 text-right">{{ money(data.health) }}</td></tr>
                <tr><td class="px-2">Aportes obligatorios a fondos de pensiones y solidaridad pensional a cargo del trabajador</td><td class="px-2 text-right">{{ money(data.pension) }}</td></tr>
                <tr class="font-bold"><td class="px-2">Valor de la retención en la fuente por rentas de trabajo</td><td class="px-2 text-right">{{ money(data.withholding) }}</td></tr>
            </table>
        </div>
        <p v-if="company.text" class="mt-3 whitespace-pre-line">{{ company.text }}</p>
        <p class="mt-3 text-[11px] text-muted">Calculado con {{ data.documents }} comprobantes de nómina del año.</p>
        <div class="mt-14 w-[300px] border-t border-[#555] pt-1">
            <div>{{ company.signer || 'Firma del retenedor' }}</div>
            <div class="text-muted">{{ company.signer_position }}</div>
        </div>
    </div>
</template>

<style scoped>
@media print { .no-print { display: none; } .sheet { box-shadow: none; margin: 0; max-width: none; } }
</style>
