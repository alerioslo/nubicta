<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import DocSearch from '../../Components/Forms/DocSearch.vue';
import NumInput from '../../Components/Forms/NumInput.vue';
import AdvanceCrossButton from '../../Components/Forms/AdvanceCrossButton.vue';

/* Recibo de pago / egreso igual al de Siigo: abono a deuda, anticipo o avanzado (líneas contables con impuestos, descuentos y ajustes). */
const props = defineProps({ form: Object, initial: Object, docTypes: Array, paymentMethods: Array, today: String });
const f = props.form;
const init = props.initial;
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const round = (v) => Math.round((Number(v) + Number.EPSILON) * 100) / 100;
const MODES = { deuda: 'Abono a deuda', anticipo: 'Anticipo', avanzado: 'Avanzado (Impuestos, descuentos y ajustes)' };
const METHOD_COLUMNS = [['name', 'Nombre'], ['account', 'Cuenta contable']];
const CONTACT_COLUMNS = [['name', 'Nombre'], ['identification', 'Identificación'], ['branch', 'Sucursal']];
const methods = computed(() => props.paymentMethods.filter((m) => !m.due));
const blankLine = () => ({ account_id: null, account_label: '', due_type: null, document_id: null, description: '', debit: 0, credit: 0 });
const detailLabel = (p) => `${p.reference ?? p.name} Cuota: 1 Fecha: ${p.due_date}`;

const doc = useForm({
    document_type: init?.document_type ?? props.docTypes[0]?.id ?? null,
    date: init?.date ?? props.today,
    contact_id: init?.contact_id ?? null, contact_label: init?.contact_label ?? '',
    mode: init?.mode ?? '',
    payment_method_id: init?.payment_method_id ?? null,
    method_label: '',
    value: init?.value ?? 0,
    lines: init?.lines?.length ? init.lines : [blankLine()],
    items: [],
    observations: init?.observations ?? '',
});
doc.method_label = methods.value.find((m) => m.id === doc.payment_method_id)?.name ?? init?.bank_name ?? '';
const pending = ref([]);
const attachment = ref(null);
const applied = computed(() => round(pending.value.reduce((a, p) => a + (p.checked ? Number(p.pay || 0) : 0), 0)));
const balance = computed(() => round(pending.value.reduce((a, p) => a + Number(p.balance || 0), 0)));
const totalDebit = computed(() => round(doc.lines.reduce((a, l) => a + Number(l.debit || 0), 0)));
const totalCredit = computed(() => round(doc.lines.reduce((a, l) => a + Number(l.credit || 0), 0)));

async function loadPending(id) {
    pending.value = [];
    if (!id) return;
    const res = await fetch(`/api/buscar/pendientes?modulo=compras&contact=${id}`, { headers: { Accept: 'application/json' } });
    pending.value = (await res.json()).map((p) => ({ ...p, checked: false, pay: 0 }));
}

async function pickContact(c) {
    if (c && !doc.mode) doc.mode = 'deuda';
    await loadPending(c?.id);
}

onMounted(async () => {
    if (!init?.contact_id) return;
    await loadPending(init.contact_id);
    for (const it of init.items ?? []) {
        let row = pending.value.find((p) => p.id === it.id);
        if (row) row.balance = it.balance;
        else pending.value.push((row = { ...it }));
        Object.assign(row, { checked: true, pay: it.pay });
    }
});

function toggle(p) {
    p.checked = !p.checked;
    p.pay = p.checked ? p.balance : 0;
    doc.value = applied.value;
}

function distribute() {
    let rest = Number(doc.value || 0);
    pending.value.forEach((p) => {
        const pay = Math.max(Math.min(rest, p.balance), 0);
        p.pay = round(pay);
        p.checked = pay > 0;
        rest -= pay;
    });
}

/* Como en Siigo: al elegir una cuenta de proveedores se agrega una línea por cada documento pendiente del tercero. */
function pickLineAccount(l, k, a) {
    l.due_type = a?.due_type ?? null;
    l.document_id = null;
    if (!a) return;
    if (!l.description) l.description = a.name;
    if (a.due_type !== 'proveedores' || !doc.contact_id || !pending.value.length) {
        if (k === doc.lines.length - 1) doc.lines.push(blankLine());
        return;
    }
    const rows = pending.value.map((p) => ({ account_id: a.id, account_label: a.label, due_type: a.due_type, document_id: p.id, description: a.name, debit: p.balance, credit: 0 }));
    doc.lines.splice(k, 1, ...rows);
}

function pickDetail(l, id) {
    l.document_id = id ? Number(id) : null;
    const p = pending.value.find((x) => x.id === l.document_id);
    if (p && !Number(l.debit) && !Number(l.credit)) l.debit = p.balance;
}

function submit() {
    doc.transform((d) => ({
        ...d,
        attachment: attachment.value,
        value: d.mode === 'avanzado' ? round(totalDebit.value - totalCredit.value) : d.value,
        items: d.mode === 'deuda' ? pending.value.filter((p) => p.checked).map((p) => ({ document_id: p.id, value: p.pay })) : [],
        lines: d.mode === 'avanzado' ? d.lines : [],
    })).post(f.url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="f.title" />
    <form class="text-[12px] text-[#333]" @submit.prevent="submit">
        <div class="flex items-start justify-between pt-[6px]">
            <h1 class="doc-title text-[24px]">{{ init ? f.title : 'Nuevo recibo de pago' }}</h1>
            <div class="flex gap-[18px]">
                <AdvanceCrossButton v-if="!init" module="compras" :contact-id="doc.contact_id" :contact-label="doc.contact_label" :date="doc.date" />
                <span class="inline-flex"><button type="submit" class="doc-btn doc-btn-green h-[24px] px-[12px]" :disabled="doc.processing">Guardar</button><span class="doc-btn doc-btn-green ml-[1px] px-[8px]"><Icon icon="chevron-down" /></span></span>
                <Link :href="f.back" class="doc-btn doc-btn-blue inline-flex items-center">Cancelar</Link>
            </div>
        </div>

        <div class="mt-[8px] grid grid-cols-[1fr_1fr] gap-x-[40px]">
            <div class="space-y-[10px]">
                <div class="grid grid-cols-[110px_1fr] items-center gap-[8px]">
                    <span class="text-right">Tipo</span>
                    <select v-model="doc.document_type" class="doc-input req"><option v-for="t in docTypes" :key="t.id" :value="t.id">{{ t.label.replace(/ - /g, '-') }}</option></select>
                </div>
                <div class="grid grid-cols-[110px_1fr] items-start gap-[8px]">
                    <span class="pt-[4px] text-right">Proveedores/otros</span>
                    <div>
                        <DocSearch v-model="doc.contact_id" v-model:label="doc.contact_label" url="/api/buscar/terceros" :columns="CONTACT_COLUMNS" display="name" create-url="/terceros/crear?tipo=proveedor"
                            :invalid="!!doc.errors.contact_id" @pick="pickContact" />
                        <p v-if="doc.errors.contact_id" class="text-[10px] text-danger">{{ doc.errors.contact_id }}</p>
                    </div>
                </div>
                <div class="grid grid-cols-[110px_1fr] items-start gap-[8px]">
                    <span class="pt-[4px] text-right">Realizar un</span>
                    <div class="flex items-start gap-[22px]">
                        <select v-model="doc.mode" class="doc-input flex-1"><option value=""></option><option v-for="(l, k) in MODES" :key="k" :value="k">{{ l }}</option></select>
                        <p v-if="doc.contact_id" class="shrink-0 text-right leading-[1.35]">Saldo a la fecha COP<br />{{ money(balance) }}</p>
                    </div>
                </div>
                <div class="grid grid-cols-[110px_1fr] items-center gap-[8px]">
                    <span class="text-right">De donde sale el dinero</span>
                    <div>
                        <DocSearch v-model="doc.payment_method_id" v-model:label="doc.method_label" :options="methods" :columns="METHOD_COLUMNS" display="name"
                            create-url="/configuracion/formas-pago/crear" placeholder="" :invalid="!!doc.errors.payment_method_id" />
                        <p v-if="doc.errors.payment_method_id" class="text-[10px] text-danger">{{ doc.errors.payment_method_id }}</p>
                    </div>
                </div>
            </div>
            <div class="space-y-[10px]">
                <div class="grid grid-cols-[110px_1fr] items-center gap-[8px]"><span class="text-right">Número</span><span>Numeración Automática</span></div>
                <div class="grid grid-cols-[110px_1fr] items-center gap-[8px]"><span class="text-right">Fecha de elaboración</span><input v-model="doc.date" type="date" class="doc-input req w-[150px]" /></div>
            </div>
        </div>

        <template v-if="doc.mode !== 'avanzado'">
            <div class="mt-[16px] flex items-center justify-end gap-[14px] pr-[36px]">
                <span>Valor pagado</span>
                <NumInput v-model="doc.value" class="w-[330px]" :class="{ 'is-invalid': doc.errors.items }" @change="doc.mode === 'deuda' && distribute()" />
            </div>
            <p v-if="doc.errors.items" class="mt-[4px] text-right text-[11px] text-danger">{{ doc.errors.items }}</p>

            <table v-if="doc.mode !== 'anticipo'" class="doc-grid mt-[16px] w-full border border-[#dcdcdc]">
                <thead><tr><th class="w-[46px]"></th><th class="text-center">Vencimiento</th><th class="text-center">Cuota</th><th class="text-center">Saldo</th><th class="w-[300px]"></th></tr></thead>
                <tbody>
                    <tr v-for="p in pending" :key="p.id" class="border-t border-[#eee]">
                        <td class="text-center"><input type="checkbox" :checked="p.checked" @change="toggle(p)" /></td>
                        <td><span class="flex justify-between"><span :title="p.name">{{ p.reference ?? p.name }}</span><span>{{ p.due_date }}</span></span></td>
                        <td class="text-center">1</td><td class="text-right">{{ money(p.balance) }}</td>
                        <td><NumInput v-model="p.pay" @focus="p.checked = true" @change="doc.value = applied" /></td>
                    </tr>
                    <tr v-if="doc.contact_id && !pending.length"><td colspan="5" class="py-[10px] text-center text-muted">El tercero no tiene cuentas por pagar pendientes</td></tr>
                </tbody>
            </table>
        </template>

        <template v-else>
            <p v-if="doc.errors.items" class="mt-[10px] text-[11px] text-danger">{{ doc.errors.items }}</p>
            <table class="doc-grid mt-[24px] w-full border border-[#dcdcdc]">
                <thead><tr><th class="w-[60px] text-center">#</th><th class="w-[16%] text-center">Cuenta contable</th><th class="w-[24%] text-center">Detalle</th><th class="text-center">Descripción</th><th class="w-[8%] text-center">Débito</th><th class="w-[8%] text-center">Crédito</th><th class="w-[8%] text-center">Acciones</th></tr></thead>
                <tbody>
                    <tr v-for="(l, k) in doc.lines" :key="k">
                        <td class="text-center">{{ k + 1 }}</td>
                        <td><DocSearch v-model="l.account_id" v-model:label="l.account_label" url="/api/cuentas" @pick="(a) => pickLineAccount(l, k, a)" /></td>
                        <td>
                            <select v-if="l.due_type === 'proveedores'" class="doc-input" :class="{ 'border-l-[3px] border-l-danger': !l.document_id }" :value="l.document_id ?? ''" @change="pickDetail(l, $event.target.value)">
                                <option value=""></option>
                                <option v-for="p in pending" :key="p.id" :value="p.id">{{ detailLabel(p) }}</option>
                            </select>
                            <input v-else class="doc-input" disabled />
                        </td>
                        <td><input v-model="l.description" class="doc-input" /></td>
                        <td><NumInput v-model="l.debit" /></td>
                        <td><NumInput v-model="l.credit" /></td>
                        <td class="text-center">
                            <button v-if="k === doc.lines.length - 1" type="button" class="text-[14px]" title="Agregar línea" @click="doc.lines.push(blankLine())"><Icon icon="plus" /></button>
                            <button v-else type="button" class="text-[13px] text-danger" title="Eliminar línea" @click="doc.lines.splice(k, 1)"><Icon icon="trash-can" /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <div class="mt-[10px] flex items-center justify-between pr-[80px]">
                <button type="button" class="flex items-center gap-[6px] px-[8px] text-[12px]" @click="doc.lines.push(blankLine())"><Icon icon="plus" /> Líneas</button>
                <span class="flex gap-[60px] font-bold text-muted"><span>Total :</span><span>{{ money(totalDebit) }}</span><span>{{ money(totalCredit) }}</span></span>
            </div>
            <div class="mt-[4px] flex justify-end gap-[60px] pr-[160px] font-bold"><span>Diferencia :</span><span>{{ money(totalDebit - totalCredit) }}</span></div>
        </template>

        <textarea v-model="doc.observations" rows="3" class="doc-input mt-[16px] h-auto max-w-[462px] py-[4px]" placeholder="Observaciones" />
        <label class="mt-[6px] flex cursor-pointer items-center gap-[10px] px-[8px] text-[11px]">Adjuntar archivo <Icon icon="paperclip" />
            <input type="file" class="hidden" @change="attachment = $event.target.files[0] ?? null" /><span class="text-muted">{{ attachment?.name }}</span></label>

        <div class="mt-[40px] flex justify-end gap-[18px]">
            <AdvanceCrossButton v-if="!init" module="compras" :contact-id="doc.contact_id" :contact-label="doc.contact_label" :date="doc.date" />
            <span class="inline-flex"><button type="submit" class="doc-btn doc-btn-green h-[24px] px-[12px]" :disabled="doc.processing">Guardar</button><span class="doc-btn doc-btn-green ml-[1px] px-[8px]"><Icon icon="chevron-down" /></span></span>
            <Link :href="f.back" class="doc-btn doc-btn-blue inline-flex items-center">Cancelar</Link>
        </div>
    </form>
</template>
