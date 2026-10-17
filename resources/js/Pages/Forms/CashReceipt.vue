<script setup>
import NumInput from '../../Components/Forms/NumInput.vue';
import { computed, onMounted, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { alertDialog } from '../../composables/dialog';
import DocSearch from '../../Components/Forms/DocSearch.vue';
import { onClickOutside } from '../../composables/clickOutside';

/* Recibo de caja igual al de Siigo: pago o abono a deuda (con impuestos y descuentos por documento), anticipo u otro ingreso. */
const props = defineProps({ form: Object, initial: Object, docTypes: Array, paymentMethods: Array, withholdings: Array, concepts: Array, today: String });
const f = props.form;
const init = props.initial;
const contactMenu = ref(false);
const contactMenuRef = ref(null);
onClickOutside(contactMenuRef, () => (contactMenu.value = false));
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const round = (v) => Math.round((Number(v) + Number.EPSILON) * 100) / 100;
const rateOf = (t) => Number(t?.percentage || 0) / (t?.type === 'ReteICA' ? 1000 : 100);
const CONTACT_COLUMNS = [['identification', 'Identificación'], ['name', 'Nombre'], ['branch', 'Suc']];
const SECTION = { deuda: 'Cuentas por pagar', anticipo: 'Datos de anticipo', otro: 'Datos de ingreso' };
const FIRST_TAB = { deuda: 'Abonos o pagos', anticipo: 'Anticipo', otro: 'Otro ingreso' };

const doc = useForm({
    mode: init?.mode ?? 'deuda',
    date: init?.date ?? props.today,
    document_type: init?.document_type ?? (props.docTypes.length === 1 ? props.docTypes[0].id : null),
    contact_id: init?.contact_id ?? null, contact_label: init?.contact_label ?? '',
    payment_method_id: init?.payment_method_id ?? null,
    value: init?.value ?? null,
    concept_id: init?.concept_id ?? null,
    items: [],
    observations: init?.observations ?? '',
});
const tab = ref('abonos');
const pending = ref([]);
const loading = ref(false);
const currentType = computed(() => props.docTypes.find((d) => d.id === doc.document_type));
const typeLabel = (t) => t.label.replace(/ - /g, '-');
const cashMethods = computed(() => props.paymentMethods.filter((m) => !m.credit));
const incomeConcepts = computed(() => props.concepts.filter((c) => c.kind === 'ingreso'));
const expenseConcepts = computed(() => props.concepts.filter((c) => c.kind === 'gasto'));

const adjOf = (p) => round(p.taxes.reduce((a, t) => a + Number(t.value || 0), 0) + p.discounts.reduce((a, d) => a + Number(d.value || 0), 0));
const totalAdj = computed(() => round(pending.value.reduce((a, p) => a + (p.checked ? adjOf(p) : 0), 0)));
const applied = computed(() => round(pending.value.reduce((a, p) => a + (p.checked ? Number(p.pay || 0) : 0), 0)));
const received = computed(() => (doc.mode === 'deuda' ? round(Math.max(Number(doc.value || 0), applied.value)) : round(Number(doc.value || 0))));
const favor = computed(() => round(Math.max(received.value - applied.value, 0)));
const clientBalance = computed(() => round(pending.value.reduce((a, p) => a + Number(p.balance || 0), 0)));
const allChecked = computed({
    get: () => pending.value.length > 0 && pending.value.every((p) => p.checked),
    set: (v) => pending.value.forEach((p) => toggle(p, v)),
});

async function loadPending(contactId) {
    pending.value = [];
    if (!contactId) return;
    loading.value = true;
    const res = await fetch(`/api/buscar/pendientes?modulo=ventas&contact=${contactId}`, { headers: { Accept: 'application/json' } });
    pending.value = (await res.json()).map((p) => ({ ...p, checked: false, pay: 0, taxes: [], discounts: [] }));
    loading.value = false;
}

async function pickContact(c) {
    await loadPending(c?.id);
}

onMounted(async () => {
    if (!init?.contact_id) return;
    await loadPending(init.contact_id);
    for (const it of init.items ?? []) {
        let row = pending.value.find((p) => p.id === it.id);
        if (row) row.balance = it.balance;
        else pending.value.push((row = { id: it.id, name: it.name, due_date: it.due_date, balance: it.balance, subtotal: it.balance, total: it.balance }));
        Object.assign(row, { checked: true, pay: it.pay, taxes: it.taxes ?? [], discounts: it.discounts ?? [] });
    }
});

function toggle(p, v = !p.checked) {
    p.checked = v;
    p.pay = v ? round(p.balance - adjOf(p)) : 0;
    doc.value = applied.value;
}

function distribute() {
    let rest = Number(doc.value || 0);
    pending.value.forEach((p) => {
        const pay = Math.max(Math.min(rest, round(p.balance - adjOf(p))), 0);
        p.pay = round(pay);
        p.checked = pay > 0;
        rest -= pay;
    });
}

/* Ventana lateral "Agregar impuesto o descuento" del documento seleccionado. */
const drawer = ref(null);
function openDrawer(p) {
    drawer.value = {
        row: p,
        taxes: p.taxes.length ? p.taxes.map((t) => ({ ...t })) : [{ tax_id: null, base: null, value: null }],
        discounts: p.discounts.length ? p.discounts.map((d) => ({ ...d })) : [{ concept_id: null, value: null }],
    };
}
const drawerTotal = computed(() => (drawer.value ? round(drawer.value.taxes.reduce((a, t) => a + Number(t.value || 0), 0) + drawer.value.discounts.reduce((a, d) => a + Number(d.value || 0), 0)) : 0));
function pickTax(t) {
    const tax = props.withholdings.find((w) => w.id === t.tax_id);
    if (!tax) return;
    if (!t.base) t.base = drawer.value.row.subtotal ?? drawer.value.row.balance;
    t.value = round(Number(t.base || 0) * rateOf(tax));
}
function changeBase(t) {
    const tax = props.withholdings.find((w) => w.id === t.tax_id);
    if (tax) t.value = round(Number(t.base || 0) * rateOf(tax));
}
function applyDrawer() {
    const p = drawer.value.row;
    p.taxes = drawer.value.taxes.filter((t) => t.tax_id && Number(t.value) > 0);
    p.discounts = drawer.value.discounts.filter((d) => d.concept_id && Number(d.value) > 0);
    if (adjOf(p) > p.balance) {
        alertDialog('Los impuestos y descuentos superan el saldo del documento');
        return;
    }
    p.checked = true;
    p.pay = round(Math.min(Number(p.pay || 0) || p.balance, p.balance - adjOf(p)));
    doc.value = applied.value;
    drawer.value = null;
}

function submit() {
    doc.transform((d) => ({
        ...d,
        value: received.value,
        items: d.mode === 'deuda' ? pending.value.filter((p) => p.checked).map((p) => ({ document_id: p.id, value: p.pay, taxes: p.taxes, discounts: p.discounts })) : [],
    })).post(f.url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="f.title" />
    <div class="-mx-3 md:-mx-[22px] flex items-center justify-between bg-primary px-[16px] py-[14px] text-white">
        <h1 class="text-[15px] font-semibold">{{ f.title }}</h1>
        <Link :href="f.back" class="text-[15px]" title="Cerrar"><Icon icon="xmark" /></Link>
    </div>

    <form class="mt-[14px] space-y-[16px] pb-[70px] text-[13px]" @submit.prevent="submit">
        <section class="s-card flex gap-[22px] px-[16px] py-[18px]">
            <label v-for="(l, k) in { deuda: 'Pago o abono a deuda', anticipo: 'Anticipo', otro: 'Otro ingreso' }" :key="k" class="flex items-center gap-[6px]">
                <input v-model="doc.mode" type="radio" :value="k" @change="tab = 'abonos'" /> {{ l }}
            </label>
        </section>

        <section class="s-card px-[16px] py-[16px]">
            <h2 class="text-[15px] font-semibold text-ink">Datos generales</h2>
            <div class="mt-[24px] grid grid-cols-3 gap-x-[16px] gap-y-[16px]">
                <label class="block">
                    <span class="text-[12px] text-ink">Fecha elaboración *</span>
                    <input v-model="doc.date" type="date" class="s-input mt-[6px] w-full" />
                </label>
                <label class="block">
                    <span class="text-[12px] text-ink">Tipo de documento *</span>
                    <select v-model="doc.document_type" class="s-input mt-[6px] w-full" :class="{ 'is-invalid': doc.errors.document_type }">
                        <option :value="null">Elige una opción</option>
                        <option v-for="t in docTypes" :key="t.id" :value="t.id">{{ typeLabel(t) }}</option>
                    </select>
                    <p v-if="doc.errors.document_type" class="s-error">{{ doc.errors.document_type }}</p>
                </label>
                <label class="block">
                    <span class="text-[12px] text-ink">Consecutivo *</span>
                    <input class="s-input mt-[6px] w-full" disabled :placeholder="currentType ? `${currentType.consecutive} (Numeración automática)` : ''" />
                </label>
                <div>
                    <span class="text-[12px] text-ink">Cliente *</span>
                    <div class="mt-[6px] flex items-center gap-[8px]">
                        <DocSearch v-model="doc.contact_id" v-model:label="doc.contact_label" class="flex-1" input-class="s-input w-full" placeholder="Busca una opción"
                            url="/api/buscar/terceros?tipo=cliente" :columns="CONTACT_COLUMNS" display="name" create-url="/terceros/crear?tipo=cliente" :invalid="!!doc.errors.contact_id" @pick="pickContact" />
                        <div ref="contactMenuRef" class="relative">
                            <button type="button" class="px-[4px] text-primary" @click="contactMenu = !contactMenu"><Icon icon="ellipsis-vertical" /></button>
                            <div v-if="contactMenu" class="absolute left-0 top-[26px] z-30 w-[140px] rounded bg-white py-[6px] text-[13px] shadow-lg">
                                <a v-if="doc.contact_id" :href="`/ventas/clientes/${doc.contact_id}`" target="_blank" class="block px-[12px] py-[6px] hover:bg-primary-light" @click="contactMenu = false">Ver perfil</a>
                                <a v-if="doc.contact_id" :href="`/terceros/${doc.contact_id}/editar`" target="_blank" class="block px-[12px] py-[6px] hover:bg-primary-light" @click="contactMenu = false">Editar cliente</a>
                                <a href="/terceros/crear?tipo=cliente" target="_blank" class="block px-[12px] py-[6px] hover:bg-primary-light" @click="contactMenu = false">Crear cliente</a>
                            </div>
                        </div>
                    </div>
                    <p v-if="doc.contact_id && !loading" class="mt-[4px] text-[12px] text-primary">Saldo actual COP: {{ money(clientBalance) }}</p>
                    <p v-if="doc.errors.contact_id" class="s-error">{{ doc.errors.contact_id }}</p>
                </div>
                <label class="block">
                    <span class="text-[12px] text-ink">Forma de pago *</span>
                    <select v-model="doc.payment_method_id" class="s-input mt-[6px] w-full" :class="{ 'is-invalid': doc.errors.payment_method_id }">
                        <option :value="null">Elige una opción</option>
                        <option v-for="m in cashMethods" :key="m.id" :value="m.id">{{ m.name }}-{{ m.account }}</option>
                    </select>
                    <p v-if="doc.errors.payment_method_id" class="s-error">{{ doc.errors.payment_method_id }}</p>
                </label>
            </div>
        </section>

        <section class="s-card">
            <div class="px-[16px] pt-[16px]">
                <h2 class="text-[15px] font-semibold text-ink">{{ SECTION[doc.mode] }}</h2>
                <div v-if="doc.mode === 'deuda'" class="mt-[18px] flex justify-end">
                    <label class="block w-[42%]">
                        <span class="text-[12px] text-ink">Ingresa el valor recibido*</span>
                        <NumInput v-model="doc.value" plain placeholder="0.00" class="s-input mt-[6px] w-full" :class="{ 'is-invalid': doc.errors.items }" @change="distribute()" />
                    </label>
                </div>
            </div>
            <nav class="s-subtabs mt-[14px] px-[16px]">
                <button type="button" class="s-subtab" :class="{ active: tab === 'abonos' }" @click="tab = 'abonos'">{{ FIRST_TAB[doc.mode] }}</button>
                <button type="button" class="s-subtab" :class="{ active: tab === 'obs' }" @click="tab = 'obs'">Observaciones y adjuntos</button>
            </nav>
            <div class="px-[16px] py-[16px]">
                <p v-if="doc.errors.items" class="s-error mb-[8px]">{{ doc.errors.items }}</p>
                <p v-if="doc.errors.value" class="s-error mb-[8px]">{{ doc.errors.value }}</p>
                <template v-if="tab === 'abonos' && doc.mode === 'deuda'">
                    <p class="mb-[14px] text-ink">Ingresa los abonos o pagos a los documentos del cliente que tienen saldos pendientes</p>
                    <table class="s-table">
                        <thead><tr><th class="w-[40px]"><input v-model="allChecked" type="checkbox" /></th><th>N°</th><th>Documento</th><th>Fecha de vencimiento <Icon icon="arrow-up" class="text-[10px] text-muted" /></th><th>Saldo</th><th>Impuestos y descuentos</th><th class="w-[200px]">Pago o abono</th></tr></thead>
                        <tbody>
                            <tr v-for="(p, i) in pending" :key="p.id">
                                <td><input type="checkbox" :checked="p.checked" @change="toggle(p)" /></td>
                                <td>{{ i + 1 }}</td>
                                <td><input class="s-input w-full" readonly :value="`${p.name}-1`" /></td>
                                <td><input class="s-input w-full" readonly :value="p.due_date" /></td>
                                <td><input class="s-input w-full text-right" readonly :value="money(p.balance)" /></td>
                                <td>
                                    <div class="flex items-center gap-[10px]">
                                        <input class="s-input w-full text-right" readonly :value="money(adjOf(p))" @click="openDrawer(p)" />
                                        <button type="button" class="text-[16px] text-primary" title="Agregar impuesto o descuento" @click="openDrawer(p)"><Icon icon="plus" /></button>
                                    </div>
                                </td>
                                <td><NumInput v-model="p.pay" plain class="s-input w-full text-right" @focus="p.checked = true" @change="doc.value = applied" /></td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="!pending.length" class="py-[40px] text-center text-muted">
                        <p class="text-ink">{{ loading ? 'Consultando documentos…' : doc.contact_id ? 'El cliente no tiene documentos con saldo pendiente' : 'No has seleccionado un cliente.' }}</p>
                        <p v-if="!doc.contact_id">Selecciona un cliente para ver sus saldos pendientes</p>
                    </div>
                    <div class="mt-[20px] grid grid-cols-[1fr_auto] gap-x-[40px] gap-y-[8px] border-b border-line pb-[14px] text-[12px]">
                        <p class="text-right">Impuestos y descuentos:&nbsp; {{ money(totalAdj) }}</p>
                        <p class="text-right">Pagos o abonos:&nbsp; {{ money(applied) }}</p>
                        <span></span>
                        <p class="text-right">Saldo a favor u otros:&nbsp; {{ money(favor) }}</p>
                    </div>
                    <p class="mt-[18px] text-right text-[17px] font-semibold text-ink">Total recibido: {{ money(received) }} COP</p>
                </template>
                <template v-else-if="tab === 'abonos' && doc.mode === 'anticipo'">
                    <p class="mb-[14px] text-ink">Ingresa el valor del anticipo. Este quedará como saldo a favor del cliente:</p>
                    <label class="block w-[32%]">
                        <span class="text-[12px] text-ink">Valor de anticipo*</span>
                        <NumInput v-model="doc.value" plain placeholder="0.00" class="s-input mt-[6px] w-full text-right" :class="{ 'is-invalid': doc.errors.items }" />
                    </label>
                </template>
                <template v-else-if="tab === 'abonos'">
                    <p class="mb-[14px] text-ink">Registra ingresos que no están vinculados a una factura.</p>
                    <div class="grid grid-cols-3 gap-x-[16px]">
                        <label class="block">
                            <span class="text-[12px] text-ink">Concepto de Ingreso*</span>
                            <span class="mt-[6px] flex items-center gap-[8px]">
                                <select v-model="doc.concept_id" class="s-input w-full" :class="{ 'is-invalid': doc.errors.concept_id }">
                                    <option :value="null">Elige una opción</option>
                                    <option v-for="c in incomeConcepts" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                                <a href="/configuracion/otros-ingresos" target="_blank" class="text-primary" title="Configurar conceptos"><Icon icon="up-right-from-square" /></a>
                            </span>
                            <p v-if="doc.errors.concept_id" class="s-error">{{ doc.errors.concept_id }}</p>
                        </label>
                        <label class="block">
                            <span class="text-[12px] text-ink">Valor*</span>
                            <NumInput v-model="doc.value" plain placeholder="0.00" class="s-input mt-[6px] w-full text-right" />
                        </label>
                    </div>
                </template>
                <div v-else>
                    <textarea v-model="doc.observations" rows="4" class="s-input h-auto w-full py-[8px]" placeholder="Observaciones" />
                </div>
            </div>
        </section>
    </form>

    <footer class="fixed bottom-0 left-[92px] right-0 z-30 flex items-center justify-between border-t border-line bg-white px-[16px] py-[10px]">
        <Link :href="f.back" class="text-[13px] text-primary">Cancelar</Link>
        <button type="button" class="s-btn s-btn-primary" :disabled="doc.processing" @click="submit">Guardar</button>
    </footer>

    <div v-if="drawer" class="fixed inset-0 z-50 bg-black/40" @click.self="drawer = null">
        <aside class="absolute bottom-0 right-0 top-0 flex w-[680px] max-w-full flex-col bg-white text-[13px]">
            <div class="flex items-center justify-between border-b border-line px-[24px] py-[16px]">
                <h2 class="text-[15px] font-semibold text-ink">Agregar impuesto o descuento</h2>
                <button type="button" @click="drawer = null"><Icon icon="xmark" /></button>
            </div>
            <div class="flex-1 space-y-[16px] overflow-y-auto px-[24px] py-[24px]">
                <div class="rounded-[4px] border border-line px-[24px] py-[22px]"><b>Documento:</b> {{ drawer.row.name }}-1</div>
                <div class="rounded-[4px] border border-line">
                    <div class="grid grid-cols-[1fr_140px_140px_30px] gap-[14px] bg-[#f4f5f7] px-[24px] py-[12px] font-semibold"><span>Impuesto</span><span>Base</span><span>Cuota</span><span></span></div>
                    <div v-for="(t, k) in drawer.taxes" :key="k" class="grid grid-cols-[1fr_140px_140px_30px] items-center gap-[14px] px-[8px] pt-[14px]">
                        <span class="flex items-center gap-[10px]">
                            <select v-model="t.tax_id" class="s-input w-full" @change="pickTax(t)">
                                <option :value="null">Selecciona una opción</option>
                                <option v-for="w in withholdings" :key="w.id" :value="w.id">{{ w.name }}</option>
                            </select>
                            <a href="/configuracion/impuestos" target="_blank" class="text-primary"><Icon icon="up-right-from-square" /></a>
                        </span>
                        <NumInput v-model="t.base" plain placeholder="0.00" class="s-input w-full" @change="changeBase(t)" />
                        <NumInput v-model="t.value" plain placeholder="0.00" class="s-input w-full" />
                        <button type="button" class="text-[#e53935]" @click="drawer.taxes.splice(k, 1)"><Icon icon="trash-can" /></button>
                    </div>
                    <div class="px-[24px] py-[16px]">
                        <button type="button" class="s-btn s-btn-outline" @click="drawer.taxes.push({ tax_id: null, base: null, value: null })"><Icon icon="plus" /> Agregar impuesto</button>
                    </div>
                </div>
                <div class="rounded-[4px] border border-line">
                    <div class="grid grid-cols-[1fr_180px_30px] gap-[14px] bg-[#f4f5f7] px-[24px] py-[12px] font-semibold"><span>Descuento o ajuste</span><span>Valor</span><span></span></div>
                    <div v-for="(d, k) in drawer.discounts" :key="k" class="grid grid-cols-[1fr_180px_30px] items-center gap-[14px] px-[8px] pt-[14px]">
                        <span class="flex items-center gap-[10px]">
                            <select v-model="d.concept_id" class="s-input w-full">
                                <option :value="null">Selecciona una opción</option>
                                <option v-for="c in expenseConcepts" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <a href="/configuracion/gastos-frecuentes" target="_blank" class="text-primary"><Icon icon="up-right-from-square" /></a>
                        </span>
                        <NumInput v-model="d.value" plain placeholder="0.00" class="s-input w-full" />
                        <button type="button" class="text-[#e53935]" @click="drawer.discounts.splice(k, 1)"><Icon icon="trash-can" /></button>
                    </div>
                    <div class="px-[24px] py-[16px]">
                        <button type="button" class="s-btn s-btn-outline" @click="drawer.discounts.push({ concept_id: null, value: null })"><Icon icon="plus" /> Agregar descuento</button>
                    </div>
                </div>
                <p class="border-t border-line pt-[20px] text-right text-[17px] font-semibold text-ink">Total Impuestos y descuentos: {{ money(drawerTotal) }} COP</p>
            </div>
            <div class="flex items-center justify-between border-t border-line px-[16px] py-[10px]">
                <button type="button" class="text-primary" @click="drawer = null">Cancelar</button>
                <button type="button" class="s-btn s-btn-primary" @click="applyDrawer">Aplicar</button>
            </div>
        </aside>
    </div>
</template>
