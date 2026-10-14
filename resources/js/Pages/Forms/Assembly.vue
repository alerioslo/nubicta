<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import DocSearch from '../../Components/Forms/DocSearch.vue';
import NumInput from '../../Components/Forms/NumInput.vue';

/* Nota de ensamble: producto a ensamblar y salida de materia prima / producto en proceso. */
const props = defineProps({ form: Object, docTypes: Array, today: String });
const f = props.form;
const KINDS = ['Materia prima - producto terminado', 'Materia prima - producto en proceso', 'Producto en proceso - producto terminado'];
const blank = () => ({ product_id: null, label: '', description: '', reference: '', unit: '', quantity: 0 });

const doc = useForm({
    document_type: props.docTypes[0]?.id ?? null, date: props.today, contact_id: null, contact_label: '', kind: KINDS[0],
    target: blank(), items: [blank()], observations: '',
});
const currentType = computed(() => props.docTypes.find((d) => d.id === doc.document_type));

function fill(row, p) {
    if (!p) return;
    row.description = p.name;
    row.reference = p.reference ?? '';
    row.unit = p.unit ?? '';
}

const attachment = ref(null);
const submit = () => doc.transform((d) => ({ ...d, attachment: attachment.value, items: [d.target, ...d.items].filter((i) => i.product_id) })).post(f.url, { preserveScroll: true });
</script>

<template>
    <Head :title="f.title" />
    <form class="text-[12px] text-[#333]" @submit.prevent="submit">
        <div class="flex items-start justify-between pt-[6px]">
            <h1 class="doc-title text-[24px]">{{ f.title }}</h1>
            <div class="flex gap-[4px]">
                <Link :href="f.back" class="doc-btn doc-btn-blue inline-flex h-[30px] items-center px-[8px]">Cancelar</Link>
                <span class="inline-flex"><button type="submit" class="doc-btn doc-btn-green h-[30px] px-[8px]" :disabled="doc.processing">Guardar</button><span class="doc-btn doc-btn-green inline-flex h-[30px] items-center border-l border-[#6aa83a] px-[10px]"><Icon icon="chevron-down" /></span></span>
            </div>
        </div>

        <div class="mt-[4px] grid grid-cols-2 gap-x-[60px] gap-y-[8px] text-[#777]">
            <div class="grid grid-cols-[110px_1fr] items-center gap-[8px]"><span class="text-right">Tipo</span><select v-model="doc.document_type" class="doc-input text-[#333]"><option v-for="t in docTypes" :key="t.id" :value="t.id">{{ t.label.replace(/^NE - (\w+) - /, 'NE-$1 - ') }}</option></select></div>
            <div class="grid grid-cols-[110px_1fr] items-center gap-[8px]"><span class="text-right">Número</span><span class="text-[#333]">{{ currentType?.consecutive }} (Numeración Automática)</span></div>
            <div class="grid grid-cols-[110px_1fr] items-center gap-[8px]"><span class="text-right">Fecha de elaboración</span><input v-model="doc.date" type="date" class="doc-input req w-[115px] text-[#333]" /></div>
            <div class="grid grid-cols-[110px_1fr] items-center gap-[8px]"><span class="text-right">Tercero</span><DocSearch v-model="doc.contact_id" v-model:label="doc.contact_label" url="/api/buscar/terceros" /></div>
            <div class="mt-[8px] grid grid-cols-[110px_1fr] items-center gap-[8px]"><span class="text-right">Tipo ensamble</span><select v-model="doc.kind" class="doc-input text-[#333]"><option v-for="k in KINDS" :key="k">{{ k }}</option></select></div>
        </div>

        <h2 class="mt-[12px] border-b border-[#ddd] pb-[2px] text-[13px] font-semibold text-[#4a90c8]">Producto a ensamblar</h2>
        <table class="doc-grid w-full"><thead><tr class="[&>th]:bg-transparent"><th class="w-[170px]"># Producto</th><th>Descripción</th><th>Referencia de fábrica</th><th>Unidad de medida</th><th class="w-[160px]">Cantidad a ensamblar</th></tr></thead>
            <tbody><tr>
                <td class="flex items-center gap-[2px]"><span>1</span><DocSearch v-model="doc.target.product_id" v-model:label="doc.target.label" class="flex-1" url="/api/buscar/productos" @pick="(p) => fill(doc.target, p)" /></td>
                <td><input v-model="doc.target.description" class="doc-input" /></td><td><input :value="doc.target.reference" class="doc-input" disabled /></td>
                <td><input :value="doc.target.unit" class="doc-input" disabled /></td><td><NumInput v-model="doc.target.quantity" /></td>
            </tr></tbody>
        </table>

        <h2 class="mt-[16px] border-b border-[#ddd] pb-[2px] text-[13px] font-semibold text-[#4a90c8]">Salida de materia prima / producto en proceso</h2>
        <table class="doc-grid w-full"><thead><tr class="[&>th]:bg-transparent"><th class="w-[170px]"># Producto</th><th>Descripción</th><th>Referencia de fábrica</th><th>Unidad de medida</th><th class="w-[150px]">Cantidad requerida (x1)</th><th class="w-[150px]">Total fórmula</th><th class="w-[40px] text-right">Acciones</th></tr></thead>
            <tbody>
                <tr v-for="(row, idx) in doc.items" :key="idx">
                    <td class="flex items-center gap-[2px]"><span>{{ idx + 1 }}</span><DocSearch v-model="row.product_id" v-model:label="row.label" class="flex-1" url="/api/buscar/productos" @pick="(p) => fill(row, p)" /></td>
                    <td><input v-model="row.description" class="doc-input" /></td><td><input :value="row.reference" class="doc-input" disabled /></td><td><input :value="row.unit" class="doc-input" disabled /></td>
                    <td><NumInput v-model="row.quantity" :decimals="6" /></td>
                    <td><input :value="(row.quantity * (doc.target.quantity || 0)).toFixed(2)" class="doc-input doc-num" disabled /></td>
                    <td class="text-right">
                        <button v-if="idx === doc.items.length - 1" type="button" class="text-[14px]" title="Agregar línea" @click="doc.items.push(blank())"><Icon icon="plus" /></button>
                        <button v-else type="button" class="text-[12px] text-[#e57373]" title="Eliminar" @click="doc.items.splice(idx, 1)"><Icon icon="trash-can" /></button>
                    </td>
                </tr>
            </tbody>
        </table>
        <button type="button" class="mt-[6px] border-y border-[#ddd] py-[6px] pl-[6px] text-left text-[12px] w-full" @click="doc.items.push(blank())"><Icon icon="plus" /> Líneas</button>
        <p v-if="doc.errors.items" class="mt-[4px] text-[11px] text-danger">{{ doc.errors.items }}</p>

        <div class="mt-[8px] flex items-start justify-between">
            <div class="w-[462px]">
                <textarea v-model="doc.observations" rows="2" class="doc-input h-auto py-[4px]" placeholder="Observaciones" />
                <label class="mt-[4px] flex cursor-pointer items-center gap-[14px] px-[8px] text-[11px]">Adjuntar archivo <Icon icon="paperclip" /><input type="file" class="hidden" @change="attachment = $event.target.files[0] ?? null" /><span class="text-muted">{{ attachment?.name }}</span></label>
            </div>
            <div class="flex gap-[4px]">
                <Link :href="f.back" class="doc-btn doc-btn-blue inline-flex h-[30px] items-center px-[8px]">Cancelar</Link>
                <span class="inline-flex"><button type="submit" class="doc-btn doc-btn-green h-[30px] px-[8px]" :disabled="doc.processing">Guardar</button><span class="doc-btn doc-btn-green inline-flex h-[30px] items-center border-l border-[#6aa83a] px-[10px]"><Icon icon="chevron-down" /></span></span>
            </div>
        </div>
    </form>
</template>
