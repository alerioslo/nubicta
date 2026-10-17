<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import DocSearch from '../../Components/Forms/DocSearch.vue';
import NumInput from '../../Components/Forms/NumInput.vue';

/* Notas de inventario de Siigo: traslado entre bodegas, ajuste de inventario y conteo fisico. */
const props = defineProps({ form: Object, warehouses: Array, today: String });
const f = props.form;
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const blank = () => ({ product_id: null, label: '', description: '', reference: '', stock: 0, from_id: null, to_id: null, direction: '', quantity: f.kind === 'traslado' ? 1 : 0, cost: 0, account_id: null, account_label: '', counted: 0 });

const doc = useForm({ action: '', date: props.today, contact_id: null, contact_label: '', items: [blank()], observations: '' });
const attachment = ref(null);

function pick(row, idx, p) {
    if (!p) return;
    row.description = p.name;
    row.reference = p.reference ?? '';
    row.stock = p.stock ?? 0;
    row.cost = Number(p.price || 0);
    if (f.kind === 'traslado' && props.warehouses.length) row.from_id ??= props.warehouses[0].id;
    if (idx === doc.items.length - 1) doc.items.push(blank());
}

function remove(idx) {
    doc.items.splice(idx, 1);
    if (!doc.items.length) doc.items.push(blank());
}

const importOpen = ref(new URLSearchParams(window.location.search).has('importar'));
const importMsg = ref([]);
const importing = ref(false);
async function importExcel(file) {
    if (!file) return;
    importing.value = true;
    importMsg.value = [];
    const body = new FormData();
    body.append('file', file);
    try {
        const res = await fetch(`/productos/lineas-excel/${f.kind}`, { method: 'POST', body, headers: { 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''), Accept: 'application/json' } });
        const data = await res.json();
        if (!res.ok) {
            importMsg.value = [{ text: data.message ?? 'No se pudo leer el archivo', ok: false }];
            return;
        }
        const rows = data.rows.map((r) => ({ ...blank(), ...r }));
        doc.items = [...doc.items.filter((i) => i.product_id), ...rows, blank()];
        importMsg.value = [{ text: `Se cargaron ${rows.length} productos.`, ok: true }, ...data.errors.map((text) => ({ text, ok: false }))];
        if (!data.errors.length) importOpen.value = false;
    } finally {
        importing.value = false;
    }
}

const submit = (action) => {
    doc.action = action;
    doc.transform((d) => ({ ...d, attachment: attachment.value, items: d.items.filter((i) => i.product_id) })).post(f.url, { preserveScroll: true });
};
</script>

<template>
    <Head :title="f.title" />
    <form class="doc-sheet flex min-h-[545px] flex-col" @submit.prevent="submit('Guardar')">
        <div class="doc-body flex-1">
            <h1 class="doc-title">{{ f.title }}</h1>
            <p v-if="f.kind === 'ajuste'" class="mt-[18px] text-[11px]"><b>Ten en cuenta:</b> Si vas a realizar un ajuste de solo cantidades debes realizarlo por medio de un
                <Link href="/inventario/conteos/crear" class="text-[#62b82d] underline">Conteo físico</Link></p>
            <p v-if="f.kind === 'conteo'" class="mt-[4px] text-[11px]"><b>Ten en cuenta:</b> Este documento solo solicita cantidades. Los valores serán calculados al momento de ejecutar el proceso de costeo al final del mes</p>

            <div class="mt-[30px] grid grid-cols-2 gap-x-[40px]">
                <div class="grid grid-cols-[66px_1fr] items-start gap-[12px] pl-[14px]">
                    <span class="doc-label">Fecha de elaboración</span>
                    <input v-model="doc.date" type="date" class="doc-input req w-[115px]" />
                </div>
                <div v-if="f.kind !== 'traslado'">
                    <div class="grid grid-cols-[66px_1fr] items-start gap-[12px]">
                        <span class="doc-label">Tercero</span>
                        <DocSearch v-model="doc.contact_id" v-model:label="doc.contact_label" url="/api/buscar/terceros" />
                    </div>
                    <button type="button" class="ml-[78px] mt-[8px] text-[10.5px] text-[#62b82d] underline" @click="importOpen = !importOpen">
                        {{ f.kind === 'conteo' ? 'Ingresar conteo físico por Excel' : 'Ingresar ajuste por Excel' }} <Icon icon="circle-info" class="text-primary" />
                    </button>
                </div>
            </div>

            <div v-if="importOpen && f.kind !== 'traslado'" class="mt-[18px] border border-[#bae6fd] bg-[#f0f9ff] px-[14px] py-[10px] text-[11px]">
                <p>Descarga la <a :href="`/productos/lineas-excel/${f.kind}/plantilla`" class="text-[#2a2ad6] underline">plantilla de Excel</a>, diligencia
                    {{ f.kind === 'conteo' ? 'el código del producto y las existencias contadas' : 'el código del producto, si aumenta o disminuye, la cantidad, el costo unitario y la cuenta contable' }}
                    y súbela para cargar las líneas (máximo 500).</p>
                <label class="mt-[8px] inline-flex cursor-pointer items-center gap-[8px] border border-[#c9c9c9] bg-white px-[12px] py-[4px]">
                    <Icon icon="file-excel" class="text-[#1d6f42]" /> {{ importing ? 'Leyendo archivo…' : 'Seleccionar archivo' }}
                    <input type="file" class="hidden" accept=".xlsx,.xls,.csv" :disabled="importing" @change="importExcel($event.target.files[0]); $event.target.value = ''" />
                </label>
                <p v-for="(m, i) in importMsg" :key="i" class="mt-[4px]" :class="m.ok ? 'text-[#2e7d32]' : 'text-danger'">{{ m.text }}</p>
            </div>

            <table class="doc-grid blue mt-[34px] w-full">
                <thead>
                    <tr v-if="f.kind === 'traslado'"><th class="w-[12px]">#</th><th class="w-[185px]">Producto</th><th>Descripción</th><th class="w-[96px]">Sale de</th><th class="w-[96px]">Entra a</th><th class="w-[92px]">Cant. a trasladar</th><th class="w-[30px]"></th></tr>
                    <tr v-else-if="f.kind === 'ajuste'"><th class="w-[12px]">#</th><th class="w-[78px]">Producto</th><th>Descripción producto</th><th class="w-[76px]">Aumenta / Disminuye</th><th class="w-[76px]">Cantidad</th><th class="w-[76px]">Costo promedio unitario <Icon icon="circle-info" /></th><th class="w-[76px]">Costo total</th><th class="w-[146px]">Cuenta contable Ingreso/Gasto <Icon icon="circle-info" /></th><th class="w-[30px]"></th></tr>
                    <tr v-else><th class="w-[12px]">#</th><th class="w-[195px]">Producto</th><th>Descripción producto</th><th class="w-[96px]">Referencia de fábrica</th><th class="w-[96px]">Existencias contadas</th><th class="w-[196px]">Ajuste de inventario</th><th class="w-[24px]"></th></tr>
                </thead>
                <tbody>
                    <tr v-for="(row, idx) in doc.items" :key="idx">
                        <td class="pt-[5px] text-[11px]">{{ idx + 1 }}</td>
                        <td><DocSearch v-model="row.product_id" v-model:label="row.label" url="/api/buscar/productos" @pick="(p) => pick(row, idx, p)" /></td>
                        <template v-if="f.kind === 'traslado'">
                            <td><input v-model="row.description" class="doc-input" /></td>
                            <td><select v-model="row.from_id" class="doc-input"><option :value="null"></option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option></select></td>
                            <td><select v-model="row.to_id" class="doc-input"><option :value="null"></option><option v-for="w in warehouses" :key="w.id" :value="w.id" :disabled="w.id === row.from_id">{{ w.name }}</option></select></td>
                            <td><NumInput v-model="row.quantity" /></td>
                        </template>
                        <template v-else-if="f.kind === 'ajuste'">
                            <td><input :value="row.description" class="doc-input" disabled /></td>
                            <td><select v-model="row.direction" class="doc-input"><option value=""></option><option value="+">Aumenta</option><option value="-">Disminuye</option></select></td>
                            <td><NumInput v-model="row.quantity" /></td>
                            <td><NumInput v-model="row.cost" /></td>
                            <td><input :value="money(row.quantity * row.cost)" class="doc-input doc-num" readonly /></td>
                            <td><DocSearch v-model="row.account_id" v-model:label="row.account_label" url="/api/cuentas" /></td>
                        </template>
                        <template v-else>
                            <td><input :value="row.description" class="doc-input" disabled /></td>
                            <td><input :value="row.reference" class="doc-input" disabled /></td>
                            <td><NumInput v-model="row.counted" /></td>
                            <td><input :value="row.product_id ? money(row.counted - row.stock) : '0.00'" class="doc-input doc-num" readonly /></td>
                        </template>
                        <td class="pt-[5px] text-center"><button type="button" class="text-[#e57373]" @click="remove(idx)"><Icon icon="trash-can" /></button></td>
                    </tr>
                </tbody>
            </table>
            <p v-if="doc.errors.items" class="mt-[4px] text-[11px] text-danger">{{ doc.errors.items }}</p>

            <p v-if="f.kind === 'ajuste'" class="doc-section mt-[30px]">Observaciones</p>
            <textarea v-model="doc.observations" rows="3" class="doc-input h-auto max-w-[356px] py-[4px]" :class="f.kind === 'ajuste' ? 'mt-[6px]' : 'mt-[20px]'" placeholder="Observaciones" />
            <label v-if="f.kind !== 'conteo'" class="mt-[6px] flex cursor-pointer items-center gap-[10px] px-[8px] text-[11px]">Adjuntar archivo <Icon icon="paperclip" />
                <input type="file" class="hidden" @change="attachment = $event.target.files[0] ?? null" /><span class="text-muted">{{ attachment?.name }}</span></label>
        </div>
        <div class="doc-footer">
            <Link :href="f.back" class="doc-btn doc-btn-blue inline-flex items-center">Cancelar</Link>
            <button v-for="b in f.buttons" :key="b" type="button" class="doc-btn doc-btn-green" :disabled="doc.processing" @click="submit(b)">{{ b }}</button>
        </div>
    </form>
</template>
