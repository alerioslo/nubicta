<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { confirmDialog } from '../../composables/dialog';
import DocSearch from '../../Components/Forms/DocSearch.vue';

/* Impuestos: grilla con edicion en linea (clic en la fila), guardar / deshacer, eliminar y "Adicionar impuesto". */
const props = defineProps({ taxes: Array, selfWithholdings: Array, types: Array, tab: String });
const tab = ref(props.tab);
const editing = ref(null);
const form = ref(null);
const errors = ref({});
const ACCOUNTS = [['sales_account', 'Ventas'], ['purchases_account', 'Compras'], ['sales_return_account', 'Devolución ventas'], ['purchases_return_account', 'Devolución compras']];
const SELF_ACCOUNTS = [['sales_account', 'Cuenta débito'], ['purchases_account', 'Cuenta crédito']];

function edit(t) {
    if (editing.value === t.id) return;
    editing.value = t.id;
    errors.value = {};
    form.value = { ...t };
}
function add() {
    const self = tab.value === 'autoretenciones';
    editing.value = 'new';
    errors.value = {};
    form.value = { id: null, active: true, code: null, name: '', type: self ? 'Autorretencion' : null, fixed_value: false, percentage: null,
        sales_account_id: null, sales_account_label: '', purchases_account_id: null, purchases_account_label: '',
        sales_return_account_id: null, sales_return_account_label: '', purchases_return_account_id: null, purchases_return_account_label: '' };
}
function undo() {
    editing.value = null;
    form.value = null;
    errors.value = {};
}
function save() {
    const data = { ...form.value };
    const opts = { preserveScroll: true, onSuccess: undo, onError: (e) => (errors.value = e) };
    if (data.id) router.put(`/configuracion/impuestos/${data.id}`, data, opts);
    else router.post('/configuracion/impuestos', data, opts);
}
async function remove(t) {
    if (await confirmDialog(`¿Eliminar el impuesto ${t.name}?`)) router.delete(`/configuracion/impuestos/${t.id}`, { preserveScroll: true });
}
const rows = () => (tab.value === 'impuestos' ? props.taxes : props.selfWithholdings);
const show = (k) => {
    tab.value = k;
    undo();
    if (!rows().length) add();
};
if (tab.value === 'autoretenciones' && !rows().length) add();
</script>

<template>
    <Head title="Impuestos" />
    <div class="flex items-center gap-[14px] pt-[20px]">
        <h1 class="doc-title text-[20px]">Impuestos</h1>
        <a href="/ayuda#impuestos" target="_blank" class="rounded-[2px] border border-[#ccc] bg-white px-[8px] py-[1px] text-[10px] text-primary">Ver tutoriales <Icon icon="caret-down" /></a>
    </div>
    <nav class="mt-[24px] flex gap-[2px] text-[12px]">
        <button v-for="[k, l] in [['impuestos', 'Impuestos'], ['autoretenciones', 'Autoretenciones']]" :key="k" type="button" class="rounded-t-[3px] border border-b-0 px-[16px] py-[8px]"
            :class="tab === k ? 'border-[#ddd] bg-white' : 'border-transparent'" @click="show(k)">{{ l }}</button>
    </nav>
    <section class="border border-[#ddd] bg-white pb-[16px] text-[11px] text-[#333]">
        <div class="s-table-wrap">
            <table class="w-full border-collapse">
                <thead class="bg-[#f4f4f4] text-[13px] text-[#4a6b8a]">
                    <tr v-if="tab === 'impuestos'">
                        <th class="w-[34px] py-[6px] font-normal">En uso</th><th class="w-[50px] text-left font-normal">Código</th><th class="w-[150px] font-normal">Nombre</th><th class="w-[130px] font-normal">Tipo de impuesto</th>
                        <th class="w-[40px] font-normal">Por valor</th><th class="w-[70px] font-normal">Tarifa</th>
                        <th v-for="[k, l] in ACCOUNTS" :key="k" class="font-normal">{{ l }}</th><th class="w-[50px]"></th>
                    </tr>
                    <tr v-else>
                        <th class="w-[34px] py-[6px] font-normal">En uso</th><th class="w-[50px] text-left font-normal">Código</th><th class="w-[150px] font-normal">Nombre</th><th class="w-[130px] font-normal">Tipo de impuesto</th>
                        <th class="w-[70px] font-normal">Tarifa</th><th v-for="[k, l] in SELF_ACCOUNTS" :key="k" class="font-normal">{{ l }}</th><th class="w-[50px]"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="t in [...rows(), ...(editing === 'new' ? [form] : [])]" :key="t.id ?? 'new'">
                        <tr v-if="editing === (t.id ?? 'new')" class="bg-[#eef2f6] align-top">
                            <td class="px-[6px] py-[6px] text-center"><input v-model="form.active" type="checkbox" /></td>
                            <td class="py-[6px]"><input v-model.number="form.code" type="number" class="doc-input" :class="{ 'is-invalid': errors.code }" /><p v-if="errors.code" class="text-[9px] text-danger">{{ errors.code }}</p></td>
                            <td class="py-[6px]"><input v-model="form.name" class="doc-input" :class="{ 'is-invalid': errors.name }" /><p v-if="errors.name" class="text-[9px] text-danger">{{ errors.name }}</p></td>
                            <td class="py-[6px]">
                                <select v-if="tab === 'impuestos'" v-model="form.type" class="doc-input" :class="{ 'is-invalid': errors.type }"><option v-for="ty in types" :key="ty">{{ ty }}</option></select>
                                <select v-else class="doc-input"><option>Autorretención 2201</option></select>
                                <p v-if="errors.type" class="text-[9px] text-danger">{{ errors.type }}</p>
                            </td>
                            <td v-if="tab === 'impuestos'" class="py-[6px] text-center"><input v-model="form.fixed_value" type="checkbox" /></td>
                            <td class="py-[6px]"><input v-model.number="form.percentage" type="number" step="any" class="doc-input text-right" :class="{ 'is-invalid': errors.percentage }" /><p v-if="errors.percentage" class="text-[9px] text-danger">{{ errors.percentage }}</p></td>
                            <td v-for="[k] in tab === 'impuestos' ? ACCOUNTS : SELF_ACCOUNTS" :key="k" class="py-[6px]">
                                <DocSearch v-model="form[`${k}_id`]" v-model:label="form[`${k}_label`]" url="/api/cuentas" :invalid="!!errors[`${k}_id`]" />
                                <p v-if="errors[`${k}_id`]" class="text-[9px] text-danger">{{ errors[`${k}_id`] }}</p>
                            </td>
                            <td class="whitespace-nowrap px-[6px] py-[8px]">
                                <button type="button" class="mr-[10px]" title="Guardar" @click="save"><Icon icon="floppy-disk" /></button>
                                <button type="button" title="Deshacer" @click="undo"><Icon icon="rotate-left" /></button>
                            </td>
                        </tr>
                        <tr v-else class="cursor-pointer align-top hover:bg-[#f2f2f2]" @click="edit(t)">
                            <td class="px-[6px] py-[6px] text-center"><input type="checkbox" :checked="t.active" disabled /></td>
                            <td class="py-[6px]">{{ t.code }}</td>
                            <td class="py-[6px]">{{ t.name }}</td>
                            <td class="py-[6px]">{{ tab === 'impuestos' ? t.type : 'Autorretención 2201' }}</td>
                            <td v-if="tab === 'impuestos'" class="py-[6px] text-center"><input type="checkbox" :checked="t.fixed_value" disabled /></td>
                            <td class="py-[6px] pr-[4px] text-right">{{ Number(t.percentage) }}</td>
                            <td v-for="[k] in tab === 'impuestos' ? ACCOUNTS : SELF_ACCOUNTS" :key="k" class="py-[6px] pr-[6px]">{{ t[`${k}_label`] }}</td>
                            <td class="px-[6px] py-[6px] text-center"><button v-if="!t.used" type="button" title="Eliminar" @click.stop="remove(t)"><Icon icon="xmark" /></button></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <button type="button" class="mt-[12px] px-[8px] text-[11px]" @click="add">Adicionar impuesto</button>
    </section>
</template>
