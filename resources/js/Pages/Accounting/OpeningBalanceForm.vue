<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import DocSearch from '../../Components/Forms/DocSearch.vue';
import NumInput from '../../Components/Forms/NumInput.vue';

/* Saldos iniciales de Siigo (cuentas por cobrar, por pagar, inventario y otras cuentas): se contabilizan en el CC-999. */
const props = defineProps({ kind: String, title: String, info: String, operations: Object, costCenters: Array, taxes: Array, today: String });

const portfolio = ['cuentas-por-cobrar', 'cuentas-por-pagar'].includes(props.kind);
const party = props.kind === 'cuentas-por-cobrar' ? 'Cliente' : 'Proveedor';
const money = (v) => Number(v || 0).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const blank = () => ({
    contact_id: null, contact_label: '', operation: '', prefix: '', number: '', quote: 1, due_date: '', value: 0, account_id: null, account_label: '',
    product_id: null, product_label: '', description: '', cost_center_id: null, quantity: 0, cost: 0, tax_id: null, debit: 0, credit: 0,
});

const doc = useForm({ date: props.today, contact_id: null, contact_label: '', items: [blank()], observations: '' });
const custom = ref(false);
const byExcel = ref(false);
const excelErrors = ref([]);
const loading = ref(false);

const totals = computed(() => {
    const sum = (fn) => doc.items.reduce((s, r) => s + fn(r), 0);
    return {
        factura: sum((r) => (r.operation === 'factura' ? Number(r.value || 0) : 0)),
        anticipo: sum((r) => (r.operation === 'anticipo' ? Number(r.value || 0) : 0)),
        inventory: sum((r) => Number(r.quantity || 0) * Number(r.cost || 0)),
        debit: sum((r) => Number(r.debit || 0)),
        credit: sum((r) => Number(r.credit || 0)),
    };
});

const add = () => doc.items.push(blank());
function remove(idx) {
    doc.items.splice(idx, 1);
    if (!doc.items.length) add();
}
function pickProduct(row, p) {
    row.description = p?.name ?? '';
    if (p && !row.cost) row.cost = Number(p.cost ?? 0);
}

async function upload(e) {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    loading.value = true;
    excelErrors.value = [];
    const body = new FormData();
    body.append('file', file);
    const token = document.querySelector('meta[name="csrf-token"]')?.content ?? decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const res = await fetch(`/contabilidad/saldos-iniciales/${props.kind}/excel`, { method: 'POST', body, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token } });
    const data = await res.json();
    loading.value = false;
    if (!res.ok) return (excelErrors.value = Object.values(data.errors ?? { e: [data.message] }).flat());
    excelErrors.value = data.errors;
    if (data.rows.length) {
        doc.items = data.rows.map((r) => ({ ...blank(), ...r }));
        if (data.rows.some((r) => r.account_id)) custom.value = true;
    }
}

const submit = () => doc.transform((d) => ({ ...d, items: d.items.map((i) => (custom.value || !portfolio ? i : { ...i, account_id: null })) })).post(`/contabilidad/saldos-iniciales/${props.kind}`, { preserveScroll: true });
</script>

<template>
    <Head :title="title" />
    <form class="mt-[6px] flex min-h-[600px] flex-col rounded bg-white text-[12px] text-[#333] shadow-[0_1px_4px_rgba(0,0,0,.12)]" @submit.prevent="submit">
        <div class="flex items-center justify-between border-b border-[#e8edf1] px-[22px] py-[14px]">
            <h1 class="text-[19px] font-semibold text-[#2d3a46]">{{ title }}</h1>
            <Link href="/contabilidad/mas-procesos" class="flex h-[30px] w-[30px] items-center justify-center rounded-full border border-[#d6dde3] text-primary" title="Cerrar"><Icon icon="xmark" /></Link>
        </div>
        <div class="doc-body flex-1">
            <p class="text-[12px] text-[#2d3a46]">{{ info }} <Link v-if="kind === 'inventario'" href="/productos/crear" class="text-primary underline">desde aquí</Link></p>

            <div class="mt-[16px] flex flex-wrap items-start gap-x-[36px] gap-y-[10px] pl-[20px]">
                <label class="flex flex-col text-[10.5px] font-semibold text-[#4b5560]">Fecha saldos iniciales<span class="text-[#e8833a]">*</span>
                    <input v-model="doc.date" type="date" class="doc-input req mt-[2px] w-[170px]" :class="{ invalid: doc.errors.date }" />
                    <span class="mt-[2px] font-normal text-muted">Formato: dd/mm/aaaa</span>
                </label>
                <label v-if="portfolio" class="mt-[12px] flex items-center gap-[8px] text-[12px]"><input v-model="custom" type="checkbox" /> Personalizar cuentas contables
                    <Icon icon="circle-info" class="text-primary" title="Elige una cuenta distinta a la predeterminada para cada saldo" /></label>
                <div v-if="kind === 'inventario'" class="mt-[8px] w-[300px]">
                    <DocSearch v-model="doc.contact_id" v-model:label="doc.contact_label" url="/api/buscar/terceros" placeholder="Cliente, Proveedor u Otros" />
                </div>
                <label class="mt-[12px] flex items-center gap-[8px] text-[12px]"><input v-model="byExcel" type="checkbox" /> Ingresar saldos por excel
                    <Icon icon="circle-info" class="text-primary" title="Descarga la plantilla, diligénciala y cárgala para llenar la grilla" /></label>
            </div>
            <p v-if="doc.errors.date" class="mt-[4px] pl-[20px] text-[11px] text-danger">{{ doc.errors.date }}</p>

            <div v-if="byExcel" class="mt-[14px] flex flex-wrap items-center gap-[14px] rounded border border-dashed border-[#b9dcf3] bg-[#f4f9fd] px-[16px] py-[12px] text-[12px]">
                <a :href="`/contabilidad/saldos-iniciales/${kind}/plantilla`" class="font-semibold text-primary underline"><Icon icon="download" /> Descargar plantilla de Excel</a>
                <label class="doc-btn doc-btn-blue inline-flex cursor-pointer items-center gap-[6px]"><Icon icon="file-excel" /> {{ loading ? 'Cargando…' : 'Cargar archivo' }}
                    <input type="file" class="hidden" accept=".xlsx,.xls,.csv" :disabled="loading" @change="upload" /></label>
                <span class="text-muted">Hasta 500 registros por archivo. Las filas cargadas quedan en la grilla para que las revises antes de contabilizar.</span>
                <ul v-if="excelErrors.length" class="w-full list-disc pl-[18px] text-danger"><li v-for="e in excelErrors.slice(0, 15)" :key="e">{{ e }}</li></ul>
            </div>

            <div class="s-table-wrap">
                <table class="doc-grid blue mt-[24px] w-full">
                    <thead>
                        <tr v-if="portfolio">
                            <th class="w-[24px]">N°</th><th>{{ party }}</th><th class="w-[150px]">Tipo de operación</th><th class="w-[70px]">Prefijo</th><th class="w-[96px]">N° comprobante</th>
                            <th class="w-[56px]">N° de cuota</th><th class="w-[118px]">Fecha de vencimiento</th><th class="w-[110px]">Valor</th><th v-if="custom" class="w-[190px]">Cuenta contable</th><th class="w-[28px]"></th>
                        </tr>
                        <tr v-else-if="kind === 'inventario'">
                            <th class="w-[24px]">N°</th><th class="w-[220px]">Producto</th><th>Descripción producto</th><th class="w-[150px]">Centro de costo</th><th class="w-[90px]">Cantidad</th>
                            <th class="w-[110px]">Costo unitario</th><th class="w-[120px]">Costo total</th><th class="w-[28px]"></th>
                        </tr>
                        <tr v-else>
                            <th class="w-[24px]">N°</th><th class="w-[230px]">Cuenta contable</th><th>Tercero</th><th class="w-[140px]">Centro de costo</th><th class="w-[140px]">Impuesto</th>
                            <th class="w-[110px]">Débito</th><th class="w-[110px]">Crédito</th><th class="w-[28px]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, idx) in doc.items" :key="idx">
                            <td class="pt-[5px] text-[11px]">{{ idx + 1 }}</td>
                            <template v-if="portfolio">
                                <td><DocSearch v-model="row.contact_id" v-model:label="row.contact_label" url="/api/buscar/terceros" /></td>
                                <td><select v-model="row.operation" class="doc-input"><option value=""></option><option v-for="(l, k) in operations" :key="k" :value="k">{{ l }}</option></select></td>
                                <td><input v-model="row.prefix" class="doc-input" maxlength="10" :disabled="row.operation === 'anticipo'" /></td>
                                <td><input v-model="row.number" class="doc-input" inputmode="numeric" /></td>
                                <td><input v-model.number="row.quote" type="number" min="1" class="doc-input" :disabled="row.operation === 'anticipo'" /></td>
                                <td><input v-model="row.due_date" type="date" class="doc-input" :disabled="row.operation === 'anticipo'" /></td>
                                <td><NumInput v-model="row.value" /></td>
                                <td v-if="custom"><DocSearch v-model="row.account_id" v-model:label="row.account_label" url="/api/cuentas" /></td>
                            </template>
                            <template v-else-if="kind === 'inventario'">
                                <td><DocSearch v-model="row.product_id" v-model:label="row.product_label" url="/api/buscar/productos" @pick="(p) => pickProduct(row, p)" /></td>
                                <td><input :value="row.description" class="doc-input" disabled /></td>
                                <td><select v-model="row.cost_center_id" class="doc-input"><option :value="null"></option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option></select></td>
                                <td><NumInput v-model="row.quantity" /></td>
                                <td><NumInput v-model="row.cost" /></td>
                                <td><input :value="money(row.quantity * row.cost)" class="doc-input doc-num" readonly /></td>
                            </template>
                            <template v-else>
                                <td><DocSearch v-model="row.account_id" v-model:label="row.account_label" url="/api/cuentas" /></td>
                                <td><DocSearch v-model="row.contact_id" v-model:label="row.contact_label" url="/api/buscar/terceros" /></td>
                                <td><select v-model="row.cost_center_id" class="doc-input"><option :value="null"></option><option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option></select></td>
                                <td><select v-model="row.tax_id" class="doc-input"><option :value="null"></option><option v-for="t in taxes" :key="t.id" :value="t.id">{{ t.name }}</option></select></td>
                                <td><NumInput v-model="row.debit" /></td>
                                <td><NumInput v-model="row.credit" /></td>
                            </template>
                            <td class="pt-[5px] text-center"><button type="button" class="text-[#e57373]" title="Eliminar" @click="remove(idx)"><Icon icon="trash-can" /></button></td>
                        </tr>
                        <tr>
                            <td class="pt-[5px] text-[11px] text-muted">{{ doc.items.length + 1 }}</td>
                            <td colspan="9"><button type="button" class="py-[4px] text-[12px] text-muted hover:text-primary" @click="add">Agregar otro ítem</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="doc.errors.items" class="mt-[4px] text-[11px] text-danger">{{ doc.errors.items }}</p>

            <div class="mt-[18px] grid grid-cols-2 gap-[30px]">
                <div>
                    <p class="text-[12px] text-[#2d3a46]">El sistema cruzará automáticamente todos los saldos iniciales contra una cuenta contable denominada ”Saldos iniciales por conciliar”</p>
                    <textarea v-model="doc.observations" rows="2" class="doc-input mt-[14px] h-auto py-[4px]" placeholder="Observaciones" />
                    <p class="text-[10px] text-muted">Aquí podrás ingresar datos y/o comentarios adicionales de los saldos iniciales</p>
                </div>
                <div class="rounded bg-white px-[20px] py-[6px] text-[12px] shadow-[0_1px_3px_rgba(0,0,0,.08)]">
                    <template v-if="portfolio">
                        <p class="flex justify-between border-b border-[#edf0f2] py-[10px]"><b>{{ kind === 'cuentas-por-cobrar' ? 'Total saldos factura de venta:' : 'Total saldo factura de compra:' }}</b><span>{{ money(totals.factura) }}</span></p>
                        <p class="flex justify-between py-[10px]"><b>{{ kind === 'cuentas-por-cobrar' ? 'Total saldos anticipo de cartera:' : 'Total saldo anticipo proveedores:' }}</b><span>{{ money(totals.anticipo) }}</span></p>
                    </template>
                    <p v-else-if="kind === 'inventario'" class="flex justify-between py-[10px]"><b>Valor total inventario:</b><span>{{ money(totals.inventory) }}</span></p>
                    <template v-else>
                        <p class="flex justify-between border-b border-[#edf0f2] py-[10px]"><b>Total débitos:</b><span>{{ money(totals.debit) }}</span></p>
                        <p class="flex justify-between border-b border-[#edf0f2] py-[10px]"><b>Total créditos:</b><span>{{ money(totals.credit) }}</span></p>
                        <p class="flex justify-between py-[10px]"><b>Saldos iniciales por conciliar:</b><span>{{ money(totals.credit - totals.debit) }}</span></p>
                    </template>
                </div>
            </div>
        </div>
        <div class="doc-footer !justify-between px-[22px]">
            <Link href="/contabilidad/mas-procesos" class="doc-btn doc-btn-blue inline-flex items-center">Cancelar</Link>
            <button type="submit" class="doc-btn doc-btn-green" :disabled="doc.processing">Contabilizar</button>
        </div>
    </form>
</template>
