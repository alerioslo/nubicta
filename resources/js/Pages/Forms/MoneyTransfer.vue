<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import DocSearch from '../../Components/Forms/DocSearch.vue';
import NumInput from '../../Components/Forms/NumInput.vue';

/* Traslado de dinero entre cuentas de caja y bancos. */
const props = defineProps({ form: Object, today: String });
const f = props.form;
const blank = () => ({ from_id: null, from_label: '', to_id: null, to_label: '', value: 0 });

const doc = useForm({ contact_id: null, contact_label: '', date: props.today, items: [blank()], observations: '' });
const attachment = ref(null);
const submit = () => doc.transform((d) => ({ ...d, attachment: attachment.value })).post(f.url, { preserveScroll: true });
</script>

<template>
    <Head :title="f.title" />
    <form class="text-[12px] text-[#555]" @submit.prevent="submit">
        <h1 class="doc-title pt-[6px] text-[24px]">{{ f.title }}</h1>
        <div class="mt-[30px] flex justify-end gap-[4px] pr-[54px]">
            <span class="inline-flex"><button type="submit" class="doc-btn doc-btn-green h-[24px] px-[8px] opacity-80" :disabled="doc.processing">Guardar</button><span class="doc-btn doc-btn-green ml-[1px] px-[5px] opacity-80"><Icon icon="chevron-down" /></span></span>
            <Link :href="f.back" class="doc-btn doc-btn-blue inline-flex items-center opacity-70">Cancelar</Link>
        </div>

        <div class="mt-[24px] grid grid-cols-2 gap-x-[40px] px-[110px]">
            <div class="grid grid-cols-[60px_1fr] items-center gap-[12px]"><span class="text-right text-[#888]">Tercero</span>
                <DocSearch v-model="doc.contact_id" v-model:label="doc.contact_label" url="/api/buscar/terceros" placeholder="" class="w-[115px]" input-class="doc-input req" /></div>
            <div class="grid grid-cols-[150px_1fr] items-center gap-[12px]"><span class="text-right text-[#888]">Fecha elaboración</span><input v-model="doc.date" type="date" class="doc-input req w-[125px]" /></div>
        </div>

        <table class="doc-grid mt-[34px] w-full [&_th]:bg-[#f4f6f8] [&_th]:text-center">
            <thead><tr><th>Sale de</th><th>Entra a</th><th>Valor</th><th class="w-[30px] bg-transparent"></th></tr></thead>
            <tbody>
                <tr v-for="(l, idx) in doc.items" :key="idx">
                    <td><DocSearch v-model="l.from_id" v-model:label="l.from_label" url="/api/cuentas?prefijo=11" placeholder="" /></td>
                    <td><DocSearch v-model="l.to_id" v-model:label="l.to_label" url="/api/cuentas?prefijo=11" placeholder="" /></td>
                    <td><NumInput v-model="l.value" /></td>
                    <td class="text-center">
                        <button v-if="idx === doc.items.length - 1" type="button" class="h-[16px] w-[14px] rounded-[2px] border border-[#999] bg-[#eee] text-[9px] leading-none" title="Agregar línea" @click="doc.items.push(blank())">+</button>
                        <button v-else type="button" class="text-[#e57373]" @click="doc.items.splice(idx, 1)"><Icon icon="trash-can" /></button>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-if="doc.errors.items" class="mt-[4px] text-[11px] text-danger">{{ doc.errors.items }}</p>
        <p v-if="doc.errors.contact_id" class="mt-[4px] text-[11px] text-danger">{{ doc.errors.contact_id }}</p>

        <textarea v-model="doc.observations" rows="3" class="doc-input mt-[26px] h-[54px] max-w-[296px] py-[4px]" placeholder="Observaciones" />
        <label class="mt-[10px] flex cursor-pointer items-center gap-[4px] px-[6px] text-[11px] text-[#888]">Adjuntar archivo <Icon icon="paperclip" />
            <input type="file" class="hidden" @change="attachment = $event.target.files[0] ?? null" /><span>{{ attachment?.name }}</span></label>

        <div class="mt-[50px] flex justify-end gap-[4px] pr-[54px]">
            <span class="inline-flex"><button type="submit" class="doc-btn doc-btn-green h-[24px] px-[8px] opacity-80" :disabled="doc.processing">Guardar</button><span class="doc-btn doc-btn-green ml-[1px] px-[5px] opacity-80"><Icon icon="chevron-down" /></span></span>
            <Link :href="f.back" class="doc-btn doc-btn-blue inline-flex items-center opacity-70">Cancelar</Link>
        </div>
    </form>
</template>
