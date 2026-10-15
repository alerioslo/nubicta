<script setup>
import NumInput from '../../Components/Forms/NumInput.vue';
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

/* Detalle de una nomina: resumen por empleado, conceptos liquidados, novedades y acciones (contabilizar, pagar, reabrir). */
const props = defineProps({ tabs: Array, run: Object, docs: Array, novelties: Array, banks: Array });

const money = (v) => Number(v ?? 0).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const open = ref(null);
const busy = ref(false);
const opts = { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) };
const STATUS = { draft: 'Borrador', posted: 'Contabilizada', Terminada: 'Terminada' };
const TYPES = { earning: 'Devengos', deduction: 'Deducciones', employer: 'Aportes del empleador', provision: 'Provisiones' };
const UNIT = { hours: 'Horas', days: 'Días', value: 'Valor' };

const novelty = useForm({ employee_id: null, concept: 'hed', quantity: null, value: null, notes: '' });
const def = computed(() => props.novelties.find((n) => n.key === novelty.concept));
const groups = computed(() => {
    const out = {};
    for (const n of props.novelties) (out[n.type] ??= []).push(n);
    return out;
});
const GROUP = { earning: 'Devengos', absence: 'Ausencias', deduction: 'Deducciones' };
const addNovelty = () => novelty.post(`/nomina/${props.run.id}/novedades`, { preserveScroll: true, onSuccess: () => novelty.reset('quantity', 'value', 'notes') });
const removeNovelty = (n) => router.delete(`/nomina/${props.run.id}/novedades/${n.id}`, opts);
const startNovelty = (d) => { novelty.employee_id = d.employee_id; open.value = d.id; };

const pay = useForm({ bank_account_id: props.banks[0]?.id ?? null, date: props.run.payment_date });
const action = (path, question) => (!question || confirm(question)) && router.post(`/nomina/${props.run.id}/${path}`, {}, opts);
const destroy = () => confirm('¿Eliminar esta nómina en borrador?') && router.delete(`/nomina/${props.run.id}`, opts);
const submitPay = () => pay.post(`/nomina/${props.run.id}/pagar`, { preserveScroll: true });
const byType = (d) => Object.keys(TYPES).map((t) => ({ type: t, items: d.items.filter((i) => i.type === t) })).filter((g) => g.items.length);
</script>

<template>
    <Head :title="run.name" />
    <PageHeader title="Nómina" :tabs="tabs" active="Nóminas" />

    <div class="mt-[24px] flex flex-wrap items-center gap-3 px-[22px]">
        <Link href="/nomina" class="text-muted"><Icon icon="arrow-left" /></Link>
        <h2 class="text-[22px] font-semibold text-primary">{{ run.name }}</h2>
        <span class="rounded-full px-3 py-[2px] text-[12px] font-semibold" :class="run.status === 'draft' ? 'bg-[#fff3cd] text-[#a86b00]' : 'bg-[#e6f4dc] text-[#5a9e2b]'">
            {{ STATUS[run.status] ?? run.status }}{{ run.payment_document ? ' · pagada ' + run.payment_document : '' }}
        </span>
        <div v-if="run.source === 'erp'" class="ml-auto flex flex-wrap gap-2">
            <template v-if="run.editable">
                <button type="button" class="s-btn s-btn-outline" :disabled="busy" @click="action('calcular')"><Icon icon="calculator" /> Recalcular</button>
                <button type="button" class="s-btn s-btn-outline text-[#c62828]" :disabled="busy" @click="destroy"><Icon icon="trash-can" /> Eliminar</button>
                <button type="button" class="s-btn s-btn-green px-5" :disabled="busy || !docs.length" @click="action('contabilizar', '¿Contabilizar la nómina? Se generará un comprobante por empleado.')"><Icon icon="check" /> Contabilizar</button>
            </template>
            <button v-else type="button" class="s-btn s-btn-outline" :disabled="busy" @click="action('reabrir', 'Se anularán los comprobantes contables y el pago de esta nómina para corregirla. ¿Continuar?')"><Icon icon="clock-rotate-left" /> Reabrir</button>
        </div>
    </div>
    <p class="px-[22px] text-[12px] text-muted">{{ run.type }} · Periodo {{ run.period }} · Pago {{ run.payment_date }}<span v-if="run.source !== 'erp'"> · Importada de Siigo (solo consulta)</span></p>

    <section class="mx-[10px] mt-[14px] grid grid-cols-2 gap-[10px] md:grid-cols-5">
        <div v-for="[label, v] in [['Devengado', run.earnings], ['Deducciones', run.deductions], ['Neto a pagar', run.total], ['Aportes empresa', run.employer_cost], ['Provisiones', run.provisions]]" :key="label" class="s-card p-3">
            <div class="text-[11.5px] text-muted">{{ label }}</div>
            <div class="text-[18px] font-semibold text-ink">$ {{ money(v) }}</div>
        </div>
    </section>

    <section v-if="run.status === 'posted' && !run.payment_document" class="s-card mx-[10px] mt-[12px] flex flex-wrap items-end gap-3 p-4 text-[12px]">
        <h3 class="w-full text-[13px] font-bold">Registrar pago (comprobante de egreso)</h3>
        <label class="flex flex-col gap-1">Pagar desde
            <select v-model="pay.bank_account_id" class="s-input h-[32px] min-w-[240px]"><option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name }}</option></select>
        </label>
        <label class="flex flex-col gap-1">Fecha <input v-model="pay.date" type="date" class="s-input h-[32px]" /></label>
        <button type="button" class="s-btn s-btn-primary" :disabled="pay.processing" @click="submitPay"><Icon icon="money-bill" /> Pagar neto ($ {{ money(run.total) }})</button>
        <span v-if="pay.errors.bank_account_id || pay.errors.date" class="text-[#c62828]">{{ pay.errors.bank_account_id || pay.errors.date }}</span>
    </section>

    <section class="s-card mx-[10px] mt-[12px] p-[16px]">
        <table class="s-table w-full text-[12.5px]">
            <thead>
                <tr>
                    <th></th><th>Empleado</th><th class="text-right">Salario</th><th class="text-right">Días</th><th class="text-right">Devengado</th>
                    <th class="text-right">Deducciones</th><th class="text-right">Neto</th><th>Comprobante</th><th class="text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="d in docs" :key="d.id">
                    <tr class="cursor-pointer hover:bg-[#f5f9fc]" @click="open = open === d.id ? null : d.id">
                        <td class="w-[24px] text-muted"><Icon :icon="open === d.id ? 'caret-down' : 'caret-right'" /></td>
                        <td><div class="font-semibold text-primary">{{ d.name }}</div><div class="text-[11px] text-muted">{{ d.identification }}</div></td>
                        <td class="text-right">{{ money(d.salary) }}</td>
                        <td class="text-right">{{ d.days }}</td>
                        <td class="text-right">{{ money(d.earnings) }}</td>
                        <td class="text-right">{{ money(d.deductions) }}</td>
                        <td class="text-right font-semibold">{{ money(d.net) }}</td>
                        <td>{{ d.ledger || '—' }}</td>
                        <td class="whitespace-nowrap text-center" @click.stop>
                            <button v-if="run.editable" type="button" class="s-btn-link mr-2" title="Agregar novedad" @click="startNovelty(d)"><Icon icon="circle-plus" /></button>
                            <a v-if="d.items.length" :href="`/nomina/${run.id}/colilla/${d.id}`" target="_blank" class="s-btn-link" title="Colilla de pago"><Icon icon="print" /></a>
                        </td>
                    </tr>
                    <tr v-if="open === d.id">
                        <td></td>
                        <td colspan="8" class="bg-[#fafbfc] py-3">
                            <div class="grid gap-4 lg:grid-cols-2">
                                <div v-for="g in byType(d)" :key="g.type">
                                    <h4 class="mb-1 text-[12px] font-bold text-ink">{{ TYPES[g.type] }}</h4>
                                    <table class="w-full text-[12px]">
                                        <tr v-for="(i, k) in g.items" :key="k" class="border-b border-line">
                                            <td class="py-[3px]">{{ i.label }}</td>
                                            <td class="text-right text-muted">{{ i.quantity ? i.quantity : '' }}</td>
                                            <td class="text-right">{{ money(i.value) }}</td>
                                        </tr>
                                    </table>
                                </div>
                                <p v-if="!d.items.length" class="text-muted">Sin conceptos liquidados (nómina importada de Siigo: el detalle está en el comprobante contable).</p>
                            </div>

                            <div v-if="d.novelties.length || run.editable" class="mt-3">
                                <h4 class="mb-1 text-[12px] font-bold text-ink">Novedades</h4>
                                <div v-for="n in d.novelties" :key="n.id" class="flex items-center gap-3 border-b border-line py-[3px] text-[12px]">
                                    <span class="flex-1">{{ n.label }}<span v-if="n.notes" class="text-muted"> · {{ n.notes }}</span></span>
                                    <span>{{ n.value ? '$ ' + money(n.value) : n.quantity }}</span>
                                    <button v-if="run.editable" type="button" class="text-[#c62828]" @click="removeNovelty(n)"><Icon icon="trash-can" /></button>
                                </div>
                                <form v-if="run.editable && novelty.employee_id === d.employee_id" class="mt-2 flex flex-wrap items-end gap-2 text-[12px]" @submit.prevent="addNovelty">
                                    <label class="flex flex-col gap-1">Concepto
                                        <select v-model="novelty.concept" class="s-input h-[30px] w-[260px]">
                                            <optgroup v-for="(list, t) in groups" :key="t" :label="GROUP[t]">
                                                <option v-for="n in list" :key="n.key" :value="n.key">{{ n.label }}</option>
                                            </optgroup>
                                        </select>
                                    </label>
                                    <label v-if="def?.unit !== 'value'" class="flex flex-col gap-1">{{ UNIT[def?.unit] }}
                                        <input v-model="novelty.quantity" type="number" step="0.5" min="0" class="s-input h-[30px] w-[90px]" />
                                    </label>
                                    <label v-else class="flex flex-col gap-1">Valor
                                        <NumInput v-model="novelty.value" plain min="0" class="s-input h-[30px] w-[130px]" />
                                    </label>
                                    <label class="flex flex-col gap-1">Observación <input v-model="novelty.notes" class="s-input h-[30px] w-[200px]" /></label>
                                    <button type="submit" class="s-btn s-btn-primary h-[30px]" :disabled="novelty.processing"><Icon icon="plus" /> Agregar</button>
                                    <span v-for="(e, k) in novelty.errors" :key="k" class="text-[#c62828]">{{ e }}</span>
                                </form>
                                <button v-else-if="run.editable" type="button" class="s-btn-link mt-1 text-[12px]" @click="startNovelty(d)"><Icon icon="plus" /> Agregar novedad</button>
                            </div>
                        </td>
                    </tr>
                </template>
                <tr v-if="!docs.length"><td colspan="9" class="py-6 text-center text-muted">Esta nómina no tiene empleados.</td></tr>
            </tbody>
        </table>
    </section>
</template>
