<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

/* Importación de terceros: paso 1 carga la plantilla, paso 2 revisa y confirma. */
const props = defineProps({ kind: String, cfg: Object, step: Number, preview: Object });

const upload = useForm({ file: null });
const confirm = useForm({});
const errors = computed(() => (props.preview?.rows ?? []).filter((r) => r.errors).length);
const base = `/terceros/importar/${props.kind}`;
const send = () => upload.post(base, { forceFormData: true });
const ROLE = { is_customer: 'Cliente', is_supplier: 'Proveedor', is_other: 'Otro' };
</script>

<template>
    <Head :title="`Importación de ${cfg.title}`" />
    <PageHeader :title="`Importación de ${cfg.title} (Paso ${step})`" :back="cfg.back" />

    <section v-if="step === 1" class="mt-[30px] max-w-[640px] text-[13px]">
        <p class="font-semibold">Recuerda que se permite hasta 2000 registros por cada archivo a importar.</p>
        <p class="mt-[10px] font-semibold">Descargar plantilla en excel: <Icon icon="circle-info" class="text-muted" />
            <a :href="`${base}/plantilla`" class="font-normal text-[#2a2ad6] underline">Descarga un archivo de ejemplo</a></p>
        <p class="mt-[6px] text-[12px] text-muted">Si la identificación y sucursal ya existen, el tercero se actualiza con los datos del archivo.</p>

        <form class="mt-[30px]" @submit.prevent="send">
            <label class="flex cursor-pointer items-center gap-[8px] border border-[#c9c9c9] bg-white px-[20px] py-[4px] text-[12px]">
                Archivo <span class="border-l-2 border-[#a00] pl-[4px]">{{ upload.file?.name ?? 'Seleccione el archivo de excel para importar' }}</span> <Icon icon="paperclip" class="text-muted" />
                <input type="file" class="hidden" accept=".xlsx,.xls,.csv" @change="upload.file = $event.target.files[0] ?? null" />
            </label>
            <p v-if="upload.errors.file" class="s-error mt-[6px]">{{ upload.errors.file }}</p>
            <button type="submit" class="mt-[26px] bg-[#62b82d] px-[8px] py-[7px] text-[12px] text-white disabled:opacity-60" :disabled="upload.processing || !upload.file">Siguiente</button>
        </form>
    </section>

    <section v-else class="s-card mt-[20px]">
        <div class="flex items-center gap-[12px] px-[16px] py-[14px] text-[13px]">
            <span>Archivo <b>{{ preview.file }}</b>: {{ preview.rows.length }} registros<span v-if="errors" class="text-danger">, {{ errors }} con errores</span>.</span>
            <div class="ml-auto flex gap-[10px]">
                <Link :href="base" class="s-btn s-btn-outline">Anterior</Link>
                <button type="button" class="s-btn s-btn-primary disabled:opacity-60" :disabled="!!errors || confirm.processing" @click="confirm.post(`${base}/confirmar`)">Importar</button>
            </div>
        </div>
        <p v-if="confirm.errors.file" class="s-error px-[16px]">{{ confirm.errors.file }}</p>
        <div class="overflow-x-auto">
            <table class="s-table w-full">
                <thead><tr><th>Fila</th><th>Identificación</th><th>Sucursal</th><th>Nombre</th><th>Tipo</th><th>Correo</th><th>Acción</th><th>Validación</th></tr></thead>
                <tbody>
                    <tr v-for="r in preview.rows" :key="r.row">
                        <td>{{ r.row }}</td><td>{{ r.identification }}</td><td>{{ r.branch_office }}</td><td>{{ r.name }}</td>
                        <td>{{ r.roles.map((x) => ROLE[x]).join(', ') }}</td><td>{{ r.email }}</td><td>{{ r.action }}</td>
                        <td><span v-if="r.errors" class="text-danger">{{ r.errors }}</span><span v-else class="s-badge s-badge-ok">Correcto</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
