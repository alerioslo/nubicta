<script setup>
import NumInput from '../../Components/Forms/NumInput.vue';
import { reactive } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

/* Contrato y datos de nomina del empleado: salario, grupo contable, seguridad social (entidades como terceros) y forma de pago. */
const props = defineProps({ tabs: Array, employee: Object, funds: Array, vacations: Object, groups: Array, contractTypes: Array, costCenters: Array, minimumWage: Number });
const e = props.employee;
const form = useForm({
    contract_number: e.contract_number, contract_type: e.contract_type || 'Indefinido', payment_frequency: e.payment_frequency || 'Mensual',
    start_date: e.start_date, end_date: e.end_date, salary: e.salary, position: e.position, pay_mode: e.pay_mode || 'nomina',
    payroll_group: e.payroll_group || 'Administración', contributor_type: e.contributor_type, contributor_subtype: e.contributor_subtype,
    integral_salary: !!e.integral_salary, transport_aid: !!e.transport_aid, health_rate: e.health_rate, pension_rate: e.pension_rate,
    eps: e.eps, pension_fund: e.pension_fund, severance_fund: e.severance_fund, arl: e.arl, ccf: e.ccf, arl_class: e.arl_class || 1,
    eps_contact_id: e.eps_contact_id, pension_contact_id: e.pension_contact_id, severance_contact_id: e.severance_contact_id,
    arl_contact_id: e.arl_contact_id, ccf_contact_id: e.ccf_contact_id, cost_center_id: e.cost_center_id,
    payment_method: e.payment_method, bank_name: e.bank_name, bank_account_type: e.bank_account_type, bank_account: e.bank_account,
    vacation_days_opening: e.vacation_days_opening, active: !!e.active, termination_reason: e.termination_reason,
});
const FUNDS = [
    ['eps', 'eps_contact_id', 'EPS (salud)'], ['pension_fund', 'pension_contact_id', 'Fondo de pensiones'], ['severance_fund', 'severance_contact_id', 'Fondo de cesantías'],
    ['arl', 'arl_contact_id', 'ARL'], ['ccf', 'ccf_contact_id', 'Caja de compensación'],
];
const ARL = { 1: 'I - 0,522%', 2: 'II - 1,044%', 3: 'III - 2,436%', 4: 'IV - 4,350%', 5: 'V - 6,960%' };
const labels = reactive(Object.fromEntries(FUNDS.map(([, k]) => [k, props.funds.find((f) => f.id === form[k])?.label ?? ''])));
const options = reactive({});

async function search(key, q) {
    labels[key] = q;
    form[key] = null;
    if (q.length < 2) return (options[key] = []);
    const res = await fetch(`/api/terceros?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
    options[key] = await res.json();
}
const pick = (key, c) => { form[key] = c.id; labels[key] = `${c.identification} - ${c.name}`; options[key] = []; };
const submit = () => form.put(`/nomina/empleados/${e.id}`);
</script>

<template>
    <Head :title="`Empleado ${employee.name}`" />
    <PageHeader title="Nómina" :tabs="tabs" active="Empleados" />

    <div class="mt-[24px] flex items-center px-[22px]">
        <Link href="/nomina/empleados" class="mr-3 text-muted"><Icon icon="arrow-left" /></Link>
        <div>
            <h2 class="text-[22px] font-semibold text-primary">{{ employee.name }}</h2>
            <p class="text-[12px] text-muted">CC {{ employee.identification }}</p>
        </div>
    </div>

    <form class="md:mx-[10px] mt-[14px] grid max-w-[1100px] gap-[12px] text-[12.5px]" @submit.prevent="submit">
        <section class="s-card p-[18px]">
            <h3 class="mb-3 text-[14px] font-bold">Contrato</h3>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <label class="flex flex-col gap-1">Número de contrato <input v-model="form.contract_number" class="s-input h-[32px]" /></label>
                <label class="flex flex-col gap-1">Tipo de contrato
                    <select v-model="form.contract_type" class="s-input h-[32px]"><option v-for="t in contractTypes" :key="t">{{ t }}</option></select>
                </label>
                <label class="flex flex-col gap-1">Fecha de inicio <input v-model="form.start_date" type="date" class="s-input h-[32px]" /></label>
                <label class="flex flex-col gap-1">Fecha de terminación <input v-model="form.end_date" type="date" class="s-input h-[32px]" /></label>
                <label class="flex flex-col gap-1">Cargo <input v-model="form.position" class="s-input h-[32px]" /></label>
                <label class="flex flex-col gap-1">Salario mensual <NumInput v-model="form.salary" plain class="s-input h-[32px]" />
                    <span v-if="form.salary && form.salary < minimumWage && form.pay_mode === 'nomina'" class="text-[11px] text-[#a86b00]">Inferior al salario mínimo ({{ Number(minimumWage).toLocaleString('es-CO') }})</span>
                </label>
                <label class="flex flex-col gap-1">Frecuencia de pago
                    <select v-model="form.payment_frequency" class="s-input h-[32px]"><option>Mensual</option><option>Quincenal</option></select>
                </label>
                <label class="flex flex-col gap-1">Grupo de nómina (gasto)
                    <select v-model="form.payroll_group" class="s-input h-[32px]"><option v-for="g in groups" :key="g">{{ g }}</option></select>
                </label>
                <label class="flex flex-col gap-1">Tipo de cotizante <input v-model="form.contributor_type" class="s-input h-[32px]" placeholder="Dependiente - Contrato laboral" /></label>
                <label class="flex flex-col gap-1">Subtipo de cotizante <input v-model="form.contributor_subtype" class="s-input h-[32px]" placeholder="Sin subtipo" /></label>
                <label class="flex flex-col gap-1">Centro de costo
                    <select v-model="form.cost_center_id" class="s-input h-[32px]">
                        <option :value="null">Ninguno</option>
                        <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1">Forma de liquidar
                    <select v-model="form.pay_mode" class="s-input h-[32px]">
                        <option value="nomina">Nómina completa (prestaciones y seguridad social)</option>
                        <option value="simple">Pago simple (jornal, sin prestaciones)</option>
                    </select>
                </label>
            </div>
            <div class="mt-3 flex flex-wrap gap-5">
                <label class="flex items-center gap-2"><input v-model="form.transport_aid" type="checkbox" /> Recibe auxilio de transporte (hasta 2 SMMLV)</label>
                <label class="flex items-center gap-2"><input v-model="form.integral_salary" type="checkbox" /> Salario integral</label>
                <label class="flex items-center gap-2"><input v-model="form.active" type="checkbox" /> Activo</label>
            </div>
            <label v-if="!form.active || form.end_date" class="mt-3 flex max-w-[400px] flex-col gap-1">Motivo de retiro <input v-model="form.termination_reason" class="s-input h-[32px]" /></label>
        </section>

        <section class="s-card p-[18px]">
            <h3 class="mb-1 text-[14px] font-bold">Seguridad social</h3>
            <p class="mb-3 text-[11.5px] text-muted">El tercero de cada entidad es el que queda en los comprobantes contables (aportes por pagar).</p>
            <div class="grid gap-3 md:grid-cols-2">
                <div v-for="[name, key, label] in FUNDS" :key="key" class="grid grid-cols-2 gap-2">
                    <label class="flex flex-col gap-1">{{ label }} <input v-model="form[name]" class="s-input h-[32px]" /></label>
                    <label class="relative flex flex-col gap-1">Tercero contable
                        <input :value="labels[key]" class="s-input h-[32px]" placeholder="Buscar NIT o nombre" @input="search(key, $event.target.value)" />
                        <div v-if="options[key]?.length" class="absolute top-[52px] z-10 max-h-[180px] w-[320px] overflow-y-auto border bg-white shadow">
                            <button v-for="c in options[key]" :key="c.id" type="button" class="block w-full px-2 py-1 text-left text-[11.5px] hover:bg-[#e0f2fe]" @click="pick(key, c)">{{ c.identification }} - {{ c.name }}</button>
                        </div>
                    </label>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <label class="flex flex-col gap-1">% salud empleado <input v-model="form.health_rate" type="number" step="0.01" class="s-input h-[32px]" /></label>
                    <label class="flex flex-col gap-1">% pensión empleado <input v-model="form.pension_rate" type="number" step="0.01" class="s-input h-[32px]" /></label>
                    <label class="flex flex-col gap-1">Clase de riesgo ARL
                        <select v-model="form.arl_class" class="s-input h-[32px]"><option v-for="(l, k) in ARL" :key="k" :value="Number(k)">{{ l }}</option></select>
                    </label>
                </div>
            </div>
        </section>

        <section class="s-card p-[18px]">
            <h3 class="mb-3 text-[14px] font-bold">Pago y vacaciones</h3>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <label class="flex flex-col gap-1">Método de pago
                    <select v-model="form.payment_method" class="s-input h-[32px]"><option>Efectivo</option><option>Transferencia</option><option>Cheque</option></select>
                </label>
                <label class="flex flex-col gap-1">Banco <input v-model="form.bank_name" class="s-input h-[32px]" /></label>
                <label class="flex flex-col gap-1">Tipo de cuenta
                    <select v-model="form.bank_account_type" class="s-input h-[32px]"><option :value="null">No aplica</option><option>Ahorros</option><option>Corriente</option></select>
                </label>
                <label class="flex flex-col gap-1">Número de cuenta <input v-model="form.bank_account" class="s-input h-[32px]" /></label>
                <label class="flex flex-col gap-1">Días de vacaciones ya disfrutados (antes del ERP)
                    <input v-model="form.vacation_days_opening" type="number" min="0" step="0.5" class="s-input h-[32px]" />
                </label>
                <div class="col-span-3 self-end text-[12px] text-muted">
                    Días causados: <b>{{ vacations.earned }}</b> · disfrutados: <b>{{ vacations.taken }}</b> · pendientes: <b class="text-ink">{{ vacations.pending }}</b>
                </div>
            </div>
        </section>

        <div v-if="Object.keys(form.errors).length" class="rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[#c62828]">
            <div v-for="(m, k) in form.errors" :key="k">{{ m }}</div>
        </div>
        <div class="flex justify-end gap-2 pb-6">
            <Link href="/nomina/empleados" class="s-btn s-btn-outline">Cancelar</Link>
            <button type="submit" class="s-btn s-btn-green px-6" :disabled="form.processing">Guardar</button>
        </div>
    </form>
</template>
