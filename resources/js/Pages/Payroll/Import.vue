<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

/* Importación de empleados, contratos o salarios en tres pasos: plantilla, carga y resultados. */
const props = defineProps({ kind: String, title: String, noun: String, preview: Object });

const base = `/configuracion/nomina/importacion/${props.kind}`;
const open = ref(props.preview ? 3 : 1);
const template = ref('blanco');
const upload = useForm({ file: null });
const confirm = useForm({});
const errors = computed(() => (props.preview?.rows ?? []).filter((r) => r.errors).length);
const send = () => upload.post(base, { forceFormData: true });
const toggle = (n) => (open.value = open.value === n ? 0 : n);
</script>

<template>
    <Head :title="title" />
    <div class="-mx-3 md:-mx-[22px] flex h-[56px] items-center bg-white px-[24px] shadow-sm">
        <h1 class="text-[20px] font-bold text-ink">{{ title }}</h1>
        <Link href="/configuracion" class="ml-auto flex h-[30px] w-[30px] items-center justify-center rounded-full border border-[#dfe3e7] text-primary"><Icon icon="xmark" /></Link>
    </div>
    <p class="mt-[18px] text-[13px]">Completa los siguientes pasos para importar {{ noun }}</p>

    <div class="s-card mt-[14px] divide-y divide-[#edf0f2] pb-[60px]">
        <section>
            <button type="button" class="flex w-full items-center gap-3 px-[22px] py-[14px] text-left" @click="toggle(1)">
                <span class="flex h-[22px] w-[22px] items-center justify-center rounded-full border text-[12px]">1</span>
                <Icon :icon="open === 1 ? 'chevron-down' : 'chevron-right'" class="text-primary" />
                <span class="text-[16px] font-bold text-ink">Descargar plantilla de excel</span>
            </button>
            <div v-if="open === 1" class="px-[44px] pb-[22px] text-[13px]">
                <p class="font-semibold">¿Qué tipo de plantilla deseas usar?</p>
                <label class="mt-3 flex items-center gap-2"><input v-model="template" type="radio" value="blanco" class="accent-primary" /> Plantilla en blanco.</label>
                <label class="mt-3 flex items-center gap-2"><input v-model="template" type="radio" value="todos" class="accent-primary" /> Plantilla con todos los {{ kind === 'empleados' ? 'empleados' : 'contratos' }} disponibles.</label>
                <a :href="`${base}/plantilla?plantilla=${template}`" class="s-btn s-btn-primary mt-4">Descargar plantilla</a>
            </div>
        </section>
        <section>
            <button type="button" class="flex w-full items-center gap-3 px-[22px] py-[14px] text-left" @click="toggle(2)">
                <span class="flex h-[22px] w-[22px] items-center justify-center rounded-full border text-[12px]">2</span>
                <Icon :icon="open === 2 ? 'chevron-down' : 'chevron-right'" class="text-primary" />
                <span class="text-[16px] font-bold text-ink">Cargar plantilla de excel</span>
            </button>
            <form v-if="open === 2" class="px-[44px] pb-[22px] text-[13px]" @submit.prevent="send">
                <p class="text-muted">Máximo 500 registros por archivo</p>
                <label class="mt-3 flex cursor-pointer flex-col items-center gap-2 rounded border-2 border-dashed border-[#c9ced3] px-[20px] py-[26px] text-center">
                    <Icon icon="cloud-arrow-up" class="text-[26px] text-primary" />
                    <span>{{ upload.file?.name ?? 'Selecciona un archivo o arrástralo aquí' }}</span>
                    <span class="text-[11px] text-muted">XLSX o XLS</span>
                    <input type="file" class="hidden" accept=".xlsx,.xls" @change="upload.file = $event.target.files[0] ?? null" />
                </label>
                <p v-if="upload.errors.file" class="s-error mt-[6px]">{{ upload.errors.file }}</p>
                <button type="submit" class="s-btn s-btn-primary mt-4" :disabled="upload.processing || !upload.file">Cargar archivo</button>
            </form>
        </section>
        <section>
            <button type="button" class="flex w-full items-center gap-3 px-[22px] py-[14px] text-left" @click="toggle(3)">
                <span class="flex h-[22px] w-[22px] items-center justify-center rounded-full border text-[12px]">3</span>
                <Icon :icon="open === 3 ? 'chevron-down' : 'chevron-right'" class="text-primary" />
                <span class="text-[16px] font-bold text-ink">Resultados de carga</span>
            </button>
            <div v-if="open === 3" class="px-[22px] pb-[22px] text-[13px]">
                <p v-if="!preview" class="px-[22px] text-muted">Carga la plantilla para ver los resultados.</p>
                <template v-else>
                    <p class="mb-3 px-[22px]">Archivo <b>{{ preview.file }}</b>: {{ preview.rows.length }} registros<span v-if="errors" class="text-danger">, {{ errors }} con errores</span>.</p>
                    <p v-if="confirm.errors.file" class="s-error px-[22px]">{{ confirm.errors.file }}</p>
                    <div class="s-table-wrap">
                        <table class="s-table">
                            <thead><tr><th>Fila</th><th>Identificación</th><th>Nombre</th><th>Acción</th><th>Validación</th></tr></thead>
                            <tbody>
                                <tr v-for="r in preview.rows" :key="r.row">
                                    <td>{{ r.row }}</td><td>{{ r.identification }}</td><td>{{ r.name }}</td><td>{{ r.action }}</td>
                                    <td><span v-if="r.errors" class="text-danger">{{ r.errors }}</span><span v-else class="s-badge s-badge-ok">Correcto</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </section>
    </div>

    <div class="fixed bottom-0 left-[92px] right-0 z-20 flex h-[54px] items-center border-t border-[#dfe3e7] bg-white px-[24px]">
        <Link href="/configuracion" class="font-semibold text-primary">Cancelar</Link>
        <button type="button" class="s-btn s-btn-primary ml-auto" :disabled="!preview || !!errors || confirm.processing" @click="confirm.post(`${base}/confirmar`)">Finalizar</button>
    </div>
</template>
