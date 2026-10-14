<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import DocSearch from '../../Components/Forms/DocSearch.vue';
import NumInput from '../../Components/Forms/NumInput.vue';
import { onClickOutside } from '../../composables/clickOutside';

/* Comprobante contable (CC) igual al de Siigo: partida doble con tercero, detalle de cartera / proveedores y "Guardar y ...". */
const props = defineProps({ form: Object, docTypes: Array, today: String, initial: { type: Object, default: null } });
const f = props.form;
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const ACCOUNT_COLUMNS = [['code', 'Código'], ['name', 'Nombre'], ['category', 'Categoría']];
const CONTACT_COLUMNS = [['identification', 'Identificación'], ['name', 'Nombre'], ['branch', 'Suc']];
const PREFIXES = [['FV', 'FV - Factura de venta'], ['FC', 'FC - Factura de compra'], ['RC', 'RC - Anticipo clientes'], ['RP', 'RP - Anticipo proveedores'], ['CC', 'CC - Otros']];
const blank = () => ({ account_id: null, account_label: '', due_type: null, third: false, contact_id: null, contact_label: '', due: null, description: '', debit: 0, credit: 0 });

const doc = useForm({ document_type: props.docTypes[0]?.id ?? null, date: props.today, items: [blank()], observations: '', after: 'guardar', ...(props.initial ?? {}) });
if (props.initial) doc.items.push(blank());
const attachment = ref(null);
const currentType = computed(() => props.docTypes.find((d) => d.id === doc.document_type));
const debit = computed(() => doc.items.reduce((a, l) => a + Number(l.debit || 0), 0));
const credit = computed(() => doc.items.reduce((a, l) => a + Number(l.credit || 0), 0));
const saveMenu = ref(false);
const saveRef = ref(null);
onClickOutside(saveRef, () => (saveMenu.value = false));

function pickAccount(l, idx, a) {
    l.due_type = a?.due_type ?? null;
    l.third = !!a?.third;
    l.due = null;
    if (idx === doc.items.length - 1) doc.items.push(blank());
    if (l.due_type && l.contact_id) recommend.value = true;
}

function pickContact(l) {
    l.due = null;
    if (l.due_type && l.contact_id) recommend.value = true;
}

function remove(idx) {
    doc.items.splice(idx, 1);
    if (!doc.items.length) doc.items.push(blank());
}

/* Recomendacion de Siigo al usar cuentas de cartera o proveedores. */
const recommend = ref(false);

/* Ventana "Detalle de cartera / proveedores". */
const detail = ref(null);
const pending = ref([]);
async function openDetail(l) {
    if (!l.due_type || !l.contact_id) return;
    detail.value = { line: l, mode: l.due?.mode ?? 'cruzar', document_id: l.due?.document_id ?? null, document_label: l.due?.mode === 'cruzar' ? l.due.label : '',
        prefix: l.due?.prefix ?? 'CC', number: l.due?.number ?? '', quote: l.due?.quote ?? 1, due_date: l.due?.due_date ?? doc.date };
    const res = await fetch(`/api/buscar/pendientes?modulo=${l.due_type === 'cartera' ? 'ventas' : 'compras'}&contact=${l.contact_id}`, { headers: { Accept: 'application/json' } });
    pending.value = (await res.json()).map((p) => ({ id: p.id, label: `${p.name}-1`, due: p.due_date, balance: money(p.balance) }));
}
function acceptDetail() {
    const d = detail.value;
    if (d.mode === 'cruzar') {
        if (!d.document_id) return;
        d.line.due = { mode: 'cruzar', document_id: d.document_id, label: d.document_label };
    } else {
        if (!d.number) return;
        d.line.due = { mode: 'crear', prefix: d.prefix, number: d.number, quote: d.quote, due_date: d.due_date, label: `${d.prefix}-${d.number}-${d.quote}` };
    }
    detail.value = null;
}

function submit(after = 'guardar') {
    saveMenu.value = false;
    doc.after = after;
    doc.transform((d) => ({ ...d, attachment: attachment.value, items: d.items.filter((l) => l.account_id || Number(l.debit) || Number(l.credit)) })).post(f.url, { preserveScroll: true });
}
</script>

<template>
    <Head :title="f.title" />
    <form class="flex min-h-[calc(100vh-70px)] flex-col text-[12px] text-[#333]" @submit.prevent="submit()">
        <div class="flex-1">
            <div class="flex items-center gap-[18px] pt-[6px]">
                <h1 class="doc-title text-[24px]">{{ f.title }}</h1>
                <a href="/ayuda#contabilidad" target="_blank" class="rounded-[2px] border border-[#ccc] bg-white px-[8px] py-[1px] text-[10px] text-primary hover:underline">Ver tutoriales <Icon icon="caret-down" /></a>
            </div>
            <div class="mt-[8px] grid grid-cols-2 gap-x-[40px] gap-y-[12px] text-[#777]">
                <div class="grid grid-cols-[125px_1fr] items-center"><span>Tipo de comprobante</span>
                    <select v-model="doc.document_type" class="doc-input req w-[218px] text-[#333]" :class="{ 'is-invalid': doc.errors.document_type }"><option v-for="t in docTypes" :key="t.id" :value="t.id">{{ t.label.replace(/^CC - (\w+) - /, 'CC-$1 - ') }}</option></select>
                </div>
                <div class="grid grid-cols-[125px_1fr] items-center"><span>Consecutivo</span><span class="text-[#333]">{{ currentType?.consecutive }} (Numeración Automática)</span></div>
                <div class="grid grid-cols-[125px_1fr] items-center"><span>Fecha de elaboración</span><input v-model="doc.date" type="date" class="doc-input req w-[115px] text-[#333]" /></div>
            </div>

            <table class="doc-grid mt-[40px] w-full">
                <thead>
                    <tr><th class="w-[16px]">#</th><th class="text-center">Cuenta contable</th><th class="text-center">Tercero</th><th class="text-center">Detalle contable</th><th class="w-[24px]"></th><th class="text-center">Descripción</th><th class="w-[90px] text-center">Débito</th><th class="w-[90px] text-center">Crédito</th><th class="w-[22px]"></th></tr>
                </thead>
                <tbody>
                    <tr v-for="(l, idx) in doc.items" :key="idx">
                        <td class="pt-[6px] text-[11px]">{{ idx + 1 }}</td>
                        <td><DocSearch v-model="l.account_id" v-model:label="l.account_label" url="/api/cuentas" :columns="ACCOUNT_COLUMNS" create-url="/contabilidad/cuentas"
                            :placeholder="idx > 0 && idx === doc.items.length - 1 ? 'Agregar otro ítem' : 'Buscar'" @pick="(a) => pickAccount(l, idx, a)" /></td>
                        <td>
                            <DocSearch v-model="l.contact_id" v-model:label="l.contact_label" url="/api/buscar/terceros" :columns="CONTACT_COLUMNS" display="name" create-url="/terceros/crear" @pick="pickContact(l)" />
                            <p v-if="(l.third || l.due_type) && !l.contact_id" class="text-[9px] text-danger">No puede estar vacío</p>
                        </td>
                        <td>
                            <input class="doc-input cursor-pointer" :class="{ 'border-l-[3px] border-l-danger': l.due_type && !l.due }" :disabled="!l.due_type" readonly :value="l.due?.label ?? ''" @click="openDetail(l)" />
                            <p v-if="l.due_type && l.contact_id && !l.due" class="text-[9px] text-danger">No puede estar vacío</p>
                        </td>
                        <td><button type="button" class="flex h-[22px] w-[22px] items-center justify-center rounded-full border border-[#555] text-[10px]" title="Detalle contable" @click="openDetail(l)"><Icon icon="arrow-up" /></button></td>
                        <td><input v-model="l.description" class="doc-input" /></td>
                        <td><NumInput v-model="l.debit" @input="l.debit && (l.credit = 0)" /></td>
                        <td><NumInput v-model="l.credit" @input="l.credit && (l.debit = 0)" /></td>
                        <td class="pt-[5px] text-center"><button v-if="l.account_id" type="button" class="text-[#e57373]" @click="remove(idx)"><Icon icon="trash-can" /></button></td>
                    </tr>
                    <tr class="font-bold"><td colspan="6" class="pr-[10px] pt-[8px] text-right">Total :</td><td class="pr-[6px] pt-[8px] text-right">{{ money(debit) }}</td><td class="pr-[6px] pt-[8px] text-right">{{ money(credit) }}</td><td></td></tr>
                    <tr class="font-bold"><td colspan="6" class="pr-[10px] text-right text-[#7cb342]">Diferencia :</td><td></td><td class="pr-[6px] text-right" :class="{ 'text-danger': Math.abs(debit - credit) > 0.009 }">{{ money(Math.abs(debit - credit)) }}</td><td></td></tr>
                </tbody>
            </table>
            <p v-if="doc.errors.items" class="mt-[4px] text-[11px] text-danger">{{ doc.errors.items }}</p>

            <p class="doc-section mt-[10px] font-normal">Observaciones</p>
            <textarea v-model="doc.observations" rows="3" class="doc-input mt-[4px] h-auto max-w-[462px] py-[4px]" placeholder="Aquí podrás ingresar datos y/o comentarios adicionales del comprobante contable" />
            <label class="mt-[14px] flex cursor-pointer items-center gap-[10px] px-[8px] text-[11px]">Adjuntar archivo <Icon icon="paperclip" />
                <input type="file" class="hidden" @change="attachment = $event.target.files[0] ?? null" /><span class="text-muted">{{ attachment?.name }}</span></label>
        </div>
        <div class="doc-footer -mx-[22px] mt-[30px]">
            <Link :href="f.back" class="doc-btn doc-btn-blue inline-flex h-[24px] items-center px-[8px]">Cancelar</Link>
            <span ref="saveRef" class="relative inline-flex">
                <button type="submit" class="doc-btn doc-btn-green" :disabled="doc.processing">Guardar</button>
                <button type="button" class="doc-btn doc-btn-green ml-[1px] px-[8px]" title="Más opciones" @click="saveMenu = !saveMenu"><Icon icon="chevron-down" class="rotate-180" /></button>
                <span v-if="saveMenu" class="absolute bottom-[calc(100%+4px)] right-0 z-30 w-[170px] rounded-[2px] border border-[#ccc] bg-white py-[4px] text-left shadow">
                    <button type="button" class="block w-full px-[12px] py-[5px] text-left hover:bg-[#f2f2f2]" @click="submit('descargar')">Guardar y descargar</button>
                    <button type="button" class="block w-full px-[12px] py-[5px] text-left hover:bg-[#f2f2f2]" @click="submit('nuevo')">Guardar y nuevo</button>
                    <button type="button" class="block w-full px-[12px] py-[5px] text-left hover:bg-[#f2f2f2]" @click="submit('guardar')">Guardar</button>
                </span>
            </span>
        </div>
    </form>

    <div v-if="recommend" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
        <div class="w-[600px] rounded-[4px] bg-white text-[13px]">
            <div class="flex items-center justify-between border-b border-line px-[28px] py-[18px]">
                <h2 class="text-[17px] font-semibold text-ink">Recomendación</h2>
                <button type="button" class="text-primary" @click="recommend = false"><Icon icon="xmark" /></button>
            </div>
            <p class="px-[28px] py-[16px] text-[12px] leading-[1.5]">Realizar ajustes en cartera o proveedores en el comprobante contable puede generar inconsistencias en los saldos, afectando la precisión de tu información. Te recomendamos usar el "Ajuste de cartera/Proveedor", una opción más segura para registrar disminuciones por motivos de descuentos, impuestos, cruzar con saldos de otros documentos, etc.</p>
            <div class="flex items-center justify-between border-t border-line px-[28px] py-[14px]">
                <button type="button" class="font-semibold text-primary" @click="recommend = false">Seguir</button>
                <Link href="/contabilidad/ajustes-cartera/crear" class="s-btn s-btn-primary">Crear ajuste de cartera/Proveedor</Link>
            </div>
        </div>
    </div>

    <div v-if="detail" class="fixed inset-0 z-50 flex items-start justify-center bg-black/40 pt-[190px]">
        <div class="w-[720px] rounded-[2px] bg-white px-[36px] pb-[14px] pt-[16px] text-[12px]">
            <h2 class="text-center text-[17px] text-primary">Detalle de cartera / proveedores</h2>
            <p class="mt-[28px]">Crea, modifica o cruza saldos de las obligaciones con terceros</p>
            <p class="mt-[16px] text-[17px] font-semibold text-ink">{{ detail.line.contact_label }}</p>
            <p class="mt-[22px] font-bold">¿Qué deseas hacer?</p>
            <div class="flex gap-[14px]">
                <label class="flex items-center gap-[4px]"><input v-model="detail.mode" type="radio" value="cruzar" /> Cruzar con saldo existente</label>
                <label class="flex items-center gap-[4px]"><input v-model="detail.mode" type="radio" value="crear" /> Crear / Modificar saldo</label>
            </div>
            <div v-if="detail.mode === 'cruzar'" class="mt-[22px]">
                <p class="font-bold">Saldos</p>
                <DocSearch v-model="detail.document_id" v-model:label="detail.document_label" :options="pending" :columns="[['label', 'Documento'], ['due', 'Vencimiento'], ['balance', 'Saldo']]" display="label" class="mt-[4px] w-[360px]" />
            </div>
            <div v-else class="mt-[22px] grid grid-cols-[200px_1fr_80px_150px] gap-x-[16px]">
                <label class="block"><span class="font-bold">Prefijo</span><select v-model="detail.prefix" class="doc-input mt-[4px]"><option v-for="[k, l] in PREFIXES" :key="k" :value="k">{{ l }}</option></select></label>
                <label class="block"><span class="font-bold">N° de comprobante</span><input v-model.number="detail.number" type="number" class="doc-input mt-[4px]" /></label>
                <label class="block"><span class="font-bold">Cuota</span><input v-model.number="detail.quote" type="number" min="1" class="doc-input mt-[4px]" /></label>
                <label class="block"><span class="font-bold">Fecha de vencimiento</span><input v-model="detail.due_date" type="date" class="doc-input req mt-[4px]" /></label>
            </div>
            <div class="mt-[14px] flex justify-center gap-[6px]">
                <button type="button" class="doc-btn doc-btn-blue h-[30px] px-[12px]" @click="detail = null">Cancelar</button>
                <button type="button" class="doc-btn doc-btn-green h-[30px] px-[12px]" @click="acceptDetail">Aceptar</button>
            </div>
        </div>
    </div>
</template>
