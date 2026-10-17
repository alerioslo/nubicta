<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

/* Importación de facturas de venta, documentos de ingreso y comprobantes contables. */
const props = defineProps({ step: Number, kind: String, kinds: Object, preview: Object, types: Array });

const base = '/configuracion/importacion-comprobantes';
const selected = ref(props.kind);
const upload = useForm({ kind: props.kind, file: null });
const confirm = useForm({});
const errors = computed(() => (props.preview?.rows ?? []).filter((r) => r.errors).length);
const vouchers = computed(() => new Set((props.preview?.rows ?? []).map((r) => `${r.type}-${r.number}`)).size);
const money = (v) => Number(v || 0).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const send = () => upload.post(base, { forceFormData: true });
</script>

<template>
    <Head title="Importación de comprobantes" />
    <h1 class="pt-[6px] text-[25px] font-normal text-primary">Importación de facturas de venta, documentos de ingreso y comprobantes contables (Paso {{ step }})</h1>

    <section v-if="step === 1" class="mt-[30px] text-[13px]">
        <p>Seleccione el tipo de comprobante</p>
        <div class="mt-[24px] grid w-[220px] grid-cols-[1fr_auto] gap-y-[4px]">
            <template v-for="(label, k) in kinds" :key="k">
                <label :for="`k-${k}`">{{ label }}</label>
                <input :id="`k-${k}`" v-model="selected" type="radio" :value="k" />
            </template>
        </div>
        <button type="button" class="mt-[26px] bg-[#62b82d] px-[8px] py-[7px] text-[12px] text-white" @click="router.get(base, { tipo: selected })">Siguiente</button>
    </section>

    <section v-else-if="step === 2" class="mt-[30px] max-w-[680px] text-[13px]">
        <p class="font-semibold">Recuerda que se permite hasta 500 registros por cada archivo a importar.</p>
        <p class="mt-[10px] font-semibold">Descargar plantilla en excel: <Icon icon="circle-info" class="text-muted" />
            <a :href="`${base}/plantilla`" class="font-normal text-[#2a2ad6] underline">Descarga un archivo de ejemplo</a></p>
        <p class="mt-[6px] text-[12px] text-muted">
            {{ kinds[kind] }} disponibles:
            <span v-for="(t, i) in types" :key="t.code">{{ t.code }} {{ t.name }}{{ i < types.length - 1 ? ', ' : '' }}</span>
            <span v-if="!types.length">ninguno. Créalo en Configuración.</span>
        </p>

        <form class="mt-[30px]" @submit.prevent="send">
            <label class="flex cursor-pointer items-center gap-[8px] border border-[#c9c9c9] bg-white px-[20px] py-[4px] text-[12px]">
                Archivo <span class="border-l-2 border-[#a00] pl-[4px]">{{ upload.file?.name ?? 'Seleccione el archivo de excel para importar' }}</span> <Icon icon="paperclip" class="text-muted" />
                <input type="file" class="hidden" accept=".xlsx,.xls,.xlsm" @change="upload.file = $event.target.files[0] ?? null" />
            </label>
            <p v-if="upload.errors.file" class="s-error mt-[6px]">{{ upload.errors.file }}</p>
            <div class="mt-[26px] flex gap-[10px]">
                <Link :href="base" class="border border-[#c9c9c9] px-[8px] py-[6px] text-[12px]">Anterior</Link>
                <button type="submit" class="bg-[#62b82d] px-[8px] py-[7px] text-[12px] text-white disabled:opacity-60" :disabled="upload.processing || !upload.file">Siguiente</button>
            </div>
        </form>
    </section>

    <section v-else class="s-card mt-[20px]">
        <div class="flex items-center gap-[12px] px-[16px] py-[14px] text-[13px]">
            <span>Archivo <b>{{ preview.file }}</b>: {{ preview.rows.length }} líneas en {{ vouchers }} comprobantes<span v-if="errors" class="text-danger">, {{ errors }} con errores</span>.</span>
            <div class="ml-auto flex gap-[10px]">
                <Link :href="`${base}?tipo=${preview.kind}`" class="s-btn s-btn-outline">Anterior</Link>
                <button type="button" class="s-btn s-btn-primary disabled:opacity-60" :disabled="!!errors || confirm.processing" @click="confirm.post(`${base}/confirmar`)">Importar</button>
            </div>
        </div>
        <p v-if="confirm.errors.file" class="s-error px-[16px]">{{ confirm.errors.file }}</p>
        <div class="overflow-x-auto">
            <table class="s-table w-full">
                <thead>
                    <tr><th>Fila</th><th>Comprobante</th><th>Fecha</th><th>Cuenta</th><th>Tercero</th><th>Descripción</th><th class="text-right">Débito</th><th class="text-right">Crédito</th><th>Validación</th></tr>
                </thead>
                <tbody>
                    <tr v-for="r in preview.rows" :key="r.row">
                        <td>{{ r.row }}</td><td>{{ r.type }}-{{ r.number }}</td><td>{{ r.date }}</td><td>{{ r.account }} {{ r.account_name }}</td>
                        <td>{{ r.identification }}</td><td>{{ r.description }}</td><td class="text-right">{{ money(r.debit) }}</td><td class="text-right">{{ money(r.credit) }}</td>
                        <td><span v-if="r.errors" class="text-danger">{{ r.errors }}</span><span v-else class="s-badge s-badge-ok">Correcto</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
