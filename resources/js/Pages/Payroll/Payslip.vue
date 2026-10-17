<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

/* Colilla (comprobante) de pago de nomina para imprimir o guardar en PDF. */
defineOptions({ layout: null });
const props = defineProps({ company: Object, run: Object, employee: Object, items: Array, totals: Object, ledger: String });
const money = (v) => Number(v ?? 0).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const earnings = computed(() => props.items.filter((i) => i.type === 'earning'));
const deductions = computed(() => props.items.filter((i) => i.type === 'deduction'));
const rows = computed(() => Array.from({ length: Math.max(earnings.value.length, deductions.value.length) }, (_, k) => [earnings.value[k], deductions.value[k]]));
const print = () => window.print();
</script>

<template>
    <Head :title="`Colilla ${employee.name}`" />
    <div class="print-bar flex justify-center gap-2 bg-[#eef1f4] py-3">
        <button type="button" class="s-btn s-btn-primary" @click="print"><Icon icon="print" /> Imprimir / Guardar PDF</button>
    </div>
    <div class="sheet mx-auto my-4 w-[800px] bg-white p-8 text-[12px] text-[#222] shadow">
        <div class="flex justify-between border-b-2 border-[#1f4e79] pb-3">
            <div>
                <div class="text-[16px] font-bold">{{ company.name }}</div>
                <div>NIT {{ company.nit }}</div>
                <div>{{ company.address }}<span v-if="company.city"> · {{ company.city }}</span></div>
            </div>
            <div class="text-right">
                <div class="text-[15px] font-bold text-[#1f4e79]">Comprobante de pago de nómina</div>
                <div>{{ run.name }}</div>
                <div>Periodo {{ run.period }}</div>
                <div>Fecha de pago {{ run.payment_date }}</div>
                <div v-if="ledger">Comprobante contable {{ ledger }}</div>
            </div>
        </div>

        <div class="s-table-wrap">
            <table class="mt-3 w-full">
                <tr><td class="w-[120px] font-semibold">Empleado</td><td>{{ employee.name }}</td><td class="w-[120px] font-semibold">Identificación</td><td>{{ employee.identification }}</td></tr>
                <tr><td class="font-semibold">Cargo</td><td>{{ employee.position || '—' }}</td><td class="font-semibold">Contrato</td><td>{{ employee.contract || '—' }}</td></tr>
                <tr><td class="font-semibold">Salario básico</td><td>$ {{ money(employee.salary) }}</td><td class="font-semibold">Días liquidados</td><td>{{ employee.days }}</td></tr>
            </table>
        </div>

        <div class="s-table-wrap">
            <table class="mt-4 w-full border-collapse">
                <thead>
                    <tr class="bg-[#1f4e79] text-white">
                        <th class="px-2 py-1 text-left">Devengos</th><th class="px-2 text-right">Cant.</th><th class="px-2 text-right">Valor</th>
                        <th class="px-2 text-left">Deducciones</th><th class="px-2 text-right">Valor</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="([e, d], k) in rows" :key="k" class="border-b border-[#ddd]">
                        <td class="px-2 py-1">{{ e?.label }}</td><td class="px-2 text-right">{{ e?.quantity || '' }}</td><td class="px-2 text-right">{{ e ? money(e.value) : '' }}</td>
                        <td class="px-2">{{ d?.label }}</td><td class="px-2 text-right">{{ d ? money(d.value) : '' }}</td>
                    </tr>
                    <tr class="font-bold">
                        <td class="px-2 py-1">Total devengado</td><td></td><td class="px-2 text-right">{{ money(totals.earnings) }}</td>
                        <td class="px-2">Total deducciones</td><td class="px-2 text-right">{{ money(totals.deductions) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-3 flex justify-end text-[15px] font-bold">Neto a pagar: $ {{ money(totals.net) }}</div>

        <div class="mt-16 grid grid-cols-2 gap-16">
            <div class="border-t border-[#555] pt-1 text-center">Firma del empleador</div>
            <div class="border-t border-[#555] pt-1 text-center">Recibí conforme · {{ employee.name }}</div>
        </div>
    </div>
</template>

<style scoped>
@media print { .print-bar { display: none; } .sheet { box-shadow: none; margin: 0; width: auto; } }
</style>
