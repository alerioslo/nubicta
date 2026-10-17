<script setup>
import { computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

/* Crear nomina o proceso de prestaciones: periodo, fecha de pago y, en liquidacion de contrato, empleado y motivo. */
const props = defineProps({ tabs: Array, kind: String, kinds: Object, defaults: Object, employees: Array, active: Number });

const form = useForm({
    kind: props.kind, frequency: 'Mensual', period_start: props.defaults.period_start, period_end: props.defaults.period_end,
    payment_date: props.defaults.period_end, employee_id: null, reason: 'Renuncia voluntaria', indemnizacion: null,
});
const REASONS = ['Renuncia voluntaria', 'Terminación con justa causa', 'Terminación sin justa causa', 'Mutuo acuerdo', 'Vencimiento del término', 'Terminación de la obra o labor'];
const HELP = {
    nomina: 'Liquida sueldo, auxilio de transporte, aportes y provisiones de cada empleado activo. Después registras novedades (extras, incapacidades, vacaciones, préstamos) y la contabilizas.',
    prima: 'Prima de servicios del semestre: un salario por año (salario + auxilio, o el promedio si es variable) proporcional a los días trabajados. Se cancela contra la provisión 2510100401.',
    cesantias: 'Cesantías (un salario por año) e intereses (12% anual) a 31 de diciembre. Las cesantías se consignan al fondo antes del 14 de febrero y los intereses se pagan al empleado en enero.',
    liquidacion: 'Liquida prima y cesantías proporcionales, intereses, vacaciones pendientes e indemnización. Al contabilizar, el contrato queda terminado en la fecha final.',
};
const selected = computed(() => props.employees.find((e) => e.id === form.employee_id));
const quincena = (n) => {
    const d = new Date(form.period_start + 'T00:00:00');
    const y = d.getFullYear(), m = String(d.getMonth() + 1).padStart(2, '0');
    const last = new Date(y, d.getMonth() + 1, 0).getDate();
    [form.period_start, form.period_end] = n === 1 ? [`${y}-${m}-01`, `${y}-${m}-15`] : [`${y}-${m}-16`, `${y}-${m}-${last}`];
    form.payment_date = form.period_end;
};
const submit = () => form.post('/nomina');
</script>

<template>
    <Head :title="kinds[kind]" />
    <PageHeader title="Nómina" :tabs="tabs" :active="kind === 'nomina' ? 'Nóminas' : 'Más procesos'" />

    <div class="mt-[24px] flex items-center px-[22px]">
        <Link href="/nomina" class="mr-3 text-muted"><Icon icon="arrow-left" /></Link>
        <h2 class="text-[22px] font-semibold text-primary">Crear {{ kinds[kind].toLowerCase() }}</h2>
    </div>

    <form class="s-card md:mx-[10px] mt-[16px] max-w-[880px] p-[22px] text-[13px]" @submit.prevent="submit">
        <p class="mb-4 rounded-[2px] bg-[#eef6fc] px-3 py-2 text-[12px] text-[#31708f]">{{ HELP[kind] }}</p>

        <div class="grid grid-cols-2 gap-4">
            <label class="flex flex-col gap-1">Tipo de proceso
                <select v-model="form.kind" class="s-input h-[34px]" @change="router.get('/nomina/crear', { tipo: form.kind })">
                    <option v-for="(label, k) in kinds" :key="k" :value="k">{{ label }}</option>
                </select>
            </label>
            <label v-if="kind === 'nomina'" class="flex flex-col gap-1">Frecuencia de pago
                <select v-model="form.frequency" class="s-input h-[34px]">
                    <option>Mensual</option><option>Quincenal</option>
                </select>
            </label>
            <label v-if="kind === 'liquidacion'" class="flex flex-col gap-1">Empleado
                <select v-model="form.employee_id" class="s-input h-[34px]">
                    <option :value="null">Selecciona...</option>
                    <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.identification }} - {{ e.name }}</option>
                </select>
                <span v-if="form.errors.employee_id" class="text-[#c62828]">{{ form.errors.employee_id }}</span>
            </label>

            <label v-if="kind !== 'liquidacion'" class="flex flex-col gap-1">Inicio del periodo <input v-model="form.period_start" type="date" class="s-input h-[34px]" /></label>
            <label class="flex flex-col gap-1">{{ kind === 'liquidacion' ? 'Fecha de terminación del contrato' : 'Fin del periodo' }}
                <input v-model="form.period_end" type="date" class="s-input h-[34px]" @change="form.payment_date = form.period_end" />
                <span v-if="form.errors.period_end" class="text-[#c62828]">{{ form.errors.period_end }}</span>
            </label>
            <label class="flex flex-col gap-1">Fecha de pago y contabilización <input v-model="form.payment_date" type="date" class="s-input h-[34px]" /></label>

            <template v-if="kind === 'liquidacion'">
                <label class="flex flex-col gap-1">Motivo de terminación
                    <select v-model="form.reason" class="s-input h-[34px]"><option v-for="r in REASONS" :key="r">{{ r }}</option></select>
                </label>
                <label class="flex flex-col gap-1">Indemnización (si aplica)
                    <input v-model="form.indemnizacion" type="number" min="0" step="1" class="s-input h-[34px]" placeholder="0" />
                </label>
                <p v-if="selected" class="col-span-2 text-[12px] text-muted">Contrato desde {{ selected.start }} · salario {{ Number(selected.salary).toLocaleString('es-CO') }}</p>
            </template>
        </div>

        <div v-if="kind === 'nomina' && form.frequency === 'Quincenal'" class="mt-3 flex gap-2">
            <button type="button" class="s-btn s-btn-outline h-[28px]" @click="quincena(1)">Primera quincena</button>
            <button type="button" class="s-btn s-btn-outline h-[28px]" @click="quincena(2)">Segunda quincena</button>
        </div>

        <p v-if="kind !== 'liquidacion'" class="mt-4 text-[12px] text-muted">Se incluirán {{ active }} empleados activos con contrato vigente en el periodo.</p>
        <p v-if="form.errors.kind" class="mt-2 text-[#c62828]">{{ form.errors.kind }}</p>

        <div class="mt-6 flex justify-end gap-2">
            <Link href="/nomina" class="s-btn s-btn-outline">Cancelar</Link>
            <button type="submit" class="s-btn s-btn-green px-6" :disabled="form.processing"><Icon icon="calculator" /> Liquidar</button>
        </div>
    </form>
</template>
