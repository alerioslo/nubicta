<script setup>
/* Representacion grafica del documento soporte de pago de nomina electronica (o su nota de ajuste). */
defineProps({ doc: Object, company: Object });
const money = (v) => Number(v || 0).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <article class="mx-auto mt-[12px] max-w-[760px] bg-white p-[28px] text-[12px] text-[#333] shadow print:shadow-none">
        <header class="flex items-start justify-between gap-[20px]">
            <div class="flex items-center gap-[12px]">
                <img v-if="company.logo" :src="company.logo" alt="" class="max-h-[60px] max-w-[120px]" />
                <div><p class="text-[14px] font-bold">{{ company.name }}</p><p>NIT {{ company.nit }}{{ company.dv ? '-' + company.dv : '' }}</p></div>
            </div>
            <div class="text-right">
                <p class="text-[14px] font-bold">{{ doc.type === 'Nota de ajuste' ? 'Nota de ajuste de documento soporte de pago de nómina electrónica' : 'Documento soporte de pago de nómina electrónica' }}</p>
                <p class="text-[16px] font-bold">{{ doc.consecutive }}</p>
                <p>Fecha: {{ doc.date }} · Periodo: {{ doc.period }}</p>
                <p v-if="doc.note">Tipo de nota: {{ doc.note }} · Predecesor: {{ doc.predecessor }}</p>
            </div>
        </header>
        <table class="mt-[18px] w-full border border-[#ccc]">
            <tbody>
                <tr><td class="w-[140px] bg-[#f4f4f4] px-[8px] py-[4px] font-semibold">Empleado</td><td class="px-[8px]">{{ doc.employee }}</td><td class="w-[120px] bg-[#f4f4f4] px-[8px] font-semibold">Identificación</td><td class="px-[8px]">{{ doc.identification }}</td></tr>
                <tr><td class="bg-[#f4f4f4] px-[8px] py-[4px] font-semibold">Contrato</td><td class="px-[8px]">{{ doc.contract }}</td><td class="bg-[#f4f4f4] px-[8px] font-semibold">Días trabajados</td><td class="px-[8px]">{{ doc.worked_days }}</td></tr>
                <tr><td class="bg-[#f4f4f4] px-[8px] py-[4px] font-semibold">Sueldo</td><td class="px-[8px]">{{ money(doc.salary) }}</td><td class="bg-[#f4f4f4] px-[8px] font-semibold">Estado DIAN</td><td class="px-[8px]">{{ doc.status }}</td></tr>
            </tbody>
        </table>
        <div class="mt-[16px] grid grid-cols-2 gap-[16px]">
            <table v-for="[title, rows, total] in [['Devengados', doc.earning_items, doc.earnings], ['Deducciones', doc.deduction_items, doc.deductions]]" :key="title" class="w-full self-start border border-[#ccc]">
                <thead><tr class="bg-[#f4f4f4]"><th class="px-[8px] py-[4px] text-left">{{ title }}</th><th class="px-[8px] text-right">Cant.</th><th class="px-[8px] text-right">Valor</th></tr></thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.label" class="border-t border-[#eee]"><td class="px-[8px] py-[3px]">{{ r.label }}</td><td class="px-[8px] text-right">{{ r.quantity || '' }}</td><td class="px-[8px] text-right">{{ money(r.value) }}</td></tr>
                    <tr class="border-t border-[#ccc] font-bold"><td class="px-[8px] py-[4px]">Total</td><td></td><td class="px-[8px] text-right">{{ money(total) }}</td></tr>
                </tbody>
            </table>
        </div>
        <p class="mt-[16px] text-right text-[15px] font-bold">Neto a pagar: $ {{ money(doc.net) }}</p>
        <p v-if="doc.cune" class="mt-[16px] break-all text-[10px]"><b>CUNE:</b> {{ doc.cune }}</p>
        <p v-if="doc.message" class="mt-[4px] text-[10px] text-muted">{{ doc.message }}</p>
    </article>
</template>
