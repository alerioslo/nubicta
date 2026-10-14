<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import DocSearch from '../../Components/Forms/DocSearch.vue';

/* Ajuste de cartera de clientes o proveedores (maximo 5 conceptos por documento). */
const props = defineProps({ form: Object, today: String });
const f = props.form;
const money = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
const MODULES = { ventas: 'Cuentas por cobrar (clientes)', compras: 'Cuentas por pagar (proveedores)' };
const CONCEPTS = ['Descuento', 'Retención', 'Ajuste al peso', 'Diferencia en cambio', 'Castigo de cartera', 'Otro'];
const blank = () => ({ concept: '', detail: '', base: 0, value: 0 });

const doc = useForm({ date: props.today, module: '', contact_id: null, contact_label: '', document_id: null, items: [blank()] });
const documents = ref([]);
const current = computed(() => documents.value.find((d) => d.id === doc.document_id));
const adjustments = computed(() => doc.items.reduce((a, i) => a + Number(i.value || 0), 0));

async function loadDocuments() {
    documents.value = [];
    doc.document_id = null;
    if (!doc.module || !doc.contact_id) return;
    const res = await fetch(`/api/buscar/pendientes?modulo=${doc.module}&contact=${doc.contact_id}`, { headers: { Accept: 'application/json' } });
    documents.value = await res.json();
}

const submit = () => doc.transform((d) => ({ ...d, items: d.items.filter((i) => i.concept) })).post(f.url, { preserveScroll: true });
</script>

<template>
    <Head :title="f.title" />
    <div class="fixed inset-0 z-40 bg-black/25" />
    <form class="fixed left-1/2 top-[112px] z-50 w-[874px] max-w-[calc(100vw-120px)] -translate-x-1/2 overflow-hidden rounded-[6px] bg-white text-[13px] shadow-2xl" @submit.prevent="submit">
        <header class="flex items-center justify-between bg-primary px-[16px] py-[14px] text-white">
            <h1 class="text-[14px] font-semibold">{{ f.title }}</h1>
            <div class="flex gap-[16px] text-[13px]"><Icon icon="up-right-and-down-left-from-center" /><Link :href="f.back" title="Cerrar"><Icon icon="xmark" /></Link></div>
        </header>
        <div class="p-[16px]">
            <div class="rounded-[6px] border border-line p-[16px]">
                <div class="grid grid-cols-3 gap-[16px]">
                    <label class="block"><span class="text-[12px] text-ink">Fecha de elaboración *</span><input v-model="doc.date" type="date" class="s-input mt-[4px] w-full" /></label>
                    <label class="block"><span class="text-[12px] text-ink">Tipo de cuenta *</span>
                        <select v-model="doc.module" class="s-input mt-[4px] w-full" @change="loadDocuments"><option value="">Selecciona un módulo</option><option v-for="(l, k) in MODULES" :key="k" :value="k">{{ l }}</option></select></label>
                    <div><span class="text-[12px] text-ink">Cliente, proveedor u otro *</span>
                        <DocSearch v-model="doc.contact_id" v-model:label="doc.contact_label" class="mt-[4px]" input-class="s-input w-full" placeholder="Buscar Cliente, proveedor u otro" url="/api/buscar/terceros" :invalid="!!doc.errors.contact_id" @pick="loadDocuments" />
                        <p v-if="doc.errors.contact_id" class="s-error">{{ doc.errors.contact_id }}</p></div>
                    <label class="block"><span class="text-[12px] text-ink">Documento</span>
                        <select v-model="doc.document_id" class="s-input mt-[4px] w-full"><option :value="null">Seleccionar documento</option><option v-for="d in documents" :key="d.id" :value="d.id">{{ d.name }} — saldo {{ money(d.balance) }}</option></select></label>
                </div>

                <p class="mt-[16px] text-[12px]">Agrega el concepto que deseas aplicar <b>(máximo 5 conceptos)</b>:</p>
                <table class="s-table mt-[10px] text-[12px]">
                    <thead><tr><th class="w-[40px]">#</th><th>Tipo de concepto</th><th>Detalle</th><th class="w-[120px]">Base</th><th class="w-[120px]">Valor Ajuste</th><th class="w-[40px]"></th></tr></thead>
                    <tbody>
                        <tr v-for="(i, idx) in doc.items" :key="idx">
                            <td>{{ idx + 1 }}</td>
                            <td><select v-model="i.concept" class="s-input h-[30px] w-full"><option value="">-</option><option v-for="c in CONCEPTS" :key="c">{{ c }}</option></select></td>
                            <td><input v-model="i.detail" class="s-input h-[30px] w-full" placeholder="-" /></td>
                            <td><input v-model.number="i.base" type="number" step="any" class="s-input h-[30px] w-full text-right" /></td>
                            <td><input v-model.number="i.value" type="number" step="any" class="s-input h-[30px] w-full text-right" /></td>
                            <td><button v-if="doc.items.length > 1" type="button" class="text-danger" @click="doc.items.splice(idx, 1)"><Icon icon="trash-can" /></button></td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="doc.errors.items" class="s-error">{{ doc.errors.items }}</p>
                <button type="button" class="s-btn s-btn-outline mt-[14px] h-[30px] text-[12px]" :disabled="doc.items.length >= 5" @click="doc.items.push(blank())"><Icon icon="plus" /> Agregar concepto</button>
                <dl class="ml-auto mt-[4px] w-[260px] space-y-[12px] text-[12px]">
                    <div class="flex justify-between"><dt>Saldo documento</dt><dd>{{ money(current?.balance ?? 0) }}</dd></div>
                    <div class="flex justify-between"><dt>Valor total ajustes</dt><dd>{{ money(adjustments) }}</dd></div>
                    <div class="flex justify-between"><dt>Nuevo saldo</dt><dd>{{ money((current?.balance ?? 0) - adjustments) }}</dd></div>
                </dl>
            </div>
        </div>
        <footer class="flex items-center justify-between border-t border-line px-[16px] py-[10px]">
            <Link :href="f.back" class="text-[12px] text-primary">Cancelar</Link>
            <button type="submit" class="s-btn s-btn-primary h-[32px]" :disabled="doc.processing">Guardar</button>
        </footer>
    </form>
</template>
