<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';

/* Importación / Subir productos desde excel (Siigo): paso 1 plantilla y archivo, paso 2 revisión y confirmación. */
const props = defineProps({ step: Number, preview: Object, fields: Array });

const upload = useForm({ file: null });
const confirm = useForm({});
const picking = ref(false);
const chosen = ref(props.fields.map((f) => f.key));
const allChosen = computed({
    get: () => chosen.value.length === props.fields.length,
    set: (v) => (chosen.value = v ? props.fields.map((f) => f.key) : props.fields.filter((f) => f.required).map((f) => f.key)),
});
const templateUrl = computed(() => '/productos/importar/plantilla?' + chosen.value.map((k) => `campos[]=${encodeURIComponent(k)}`).join('&'));
const errors = computed(() => (props.preview?.rows ?? []).filter((r) => r.errors).length);
const dragging = ref(false);
function pick(file) {
    upload.file = file ?? null;
    dragging.value = false;
}
const send = () => upload.post('/productos/importar', { forceFormData: true });
</script>

<template>
    <Head title="Importación de productos y servicios" />
    <PageHeader title="Importación / Subir productos desde excel" back="/productos" />

    <section v-if="step === 1" class="s-card mt-[18px] max-w-[880px] p-[24px] text-[13px]">
        <p class="font-semibold">Paso 1:</p>
        <p class="mt-[4px]">Crea una plantilla si vas a cargar productos nuevos o descarga la plantilla para modificar los productos existentes si vas a actualizarlos.</p>
        <div class="mt-[14px] flex flex-wrap gap-3">
            <button type="button" class="s-btn s-btn-outline" @click="picking = true">Crear plantilla</button>
            <a href="/productos/importar/existentes" class="s-btn s-btn-outline">Descargar plantilla para modificar los productos existentes</a>
        </div>

        <p class="mt-[28px] font-semibold">Paso 2:</p>
        <p class="mt-[4px]">Selecciona la plantilla con tus productos.</p>
        <p class="mt-[8px] text-muted">No se pueden importar <b>Combos</b>. Para crearlos, haz clic en <b>+Crear</b> y selecciona <b>Producto / Servicio</b>.</p>
        <p class="mt-[4px] text-muted">Máximo 500 registros por archivo</p>
        <form class="mt-[14px]" @submit.prevent="send">
            <label class="flex cursor-pointer flex-col items-center justify-center rounded-[6px] border-2 border-dashed py-[28px]"
                :class="dragging ? 'border-primary bg-[#f0f7fd]' : 'border-line'"
                @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="pick($event.dataTransfer.files[0])">
                <Icon icon="cloud-arrow-up" class="text-[26px] text-primary" />
                <span class="mt-[8px]"><span class="text-primary underline">Selecciona un archivo</span> o arrastra aquí el archivo .XLSX - Máx. 1 MB</span>
                <span v-if="upload.file" class="mt-[6px] font-semibold">{{ upload.file.name }}</span>
                <input type="file" class="hidden" accept=".xlsx" @change="pick($event.target.files[0])" />
            </label>
            <p v-if="upload.errors.file" class="s-error mt-[6px]">{{ upload.errors.file }}</p>
            <div class="mt-[20px] flex justify-end gap-3">
                <Link href="/productos" class="s-btn s-btn-outline">Cancelar</Link>
                <button type="submit" class="s-btn s-btn-primary disabled:opacity-60" :disabled="upload.processing || !upload.file">Importar</button>
            </div>
        </form>
    </section>

    <section v-else class="s-card mt-[18px]">
        <div class="flex items-center gap-[12px] px-[16px] py-[14px] text-[13px]">
            <span>Archivo <b>{{ preview.file }}</b>: {{ preview.rows.length }} registros<span v-if="errors" class="text-danger">, {{ errors }} con errores</span>.</span>
            <div class="ml-auto flex gap-[10px]">
                <Link href="/productos/importar" class="s-btn s-btn-outline">Anterior</Link>
                <button type="button" class="s-btn s-btn-primary disabled:opacity-60" :disabled="!!errors || confirm.processing" @click="confirm.post('/productos/importar/confirmar')">Importar</button>
            </div>
        </div>
        <p v-if="confirm.errors.file" class="s-error px-[16px]">{{ confirm.errors.file }}</p>
        <div class="overflow-x-auto">
            <table class="s-table w-full">
                <thead><tr><th>Fila</th><th>Tipo</th><th>Categoría</th><th>Código</th><th>Nombre</th><th>Inventariable</th><th>Acción</th><th>Validación</th></tr></thead>
                <tbody>
                    <tr v-for="r in preview.rows" :key="r.row">
                        <td>{{ r.row }}</td><td>{{ r.type_label }}</td><td>{{ r.group }}</td><td>{{ r.code }}</td><td>{{ r.name }}</td><td>{{ r.stock_control ? 'Sí' : 'No' }}</td><td>{{ r.action }}</td>
                        <td><span v-if="r.errors" class="text-danger">{{ r.errors }}</span><span v-else class="s-badge s-badge-ok">Correcto</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <div v-if="picking" class="fixed inset-0 z-50 flex items-center justify-center p-3 bg-black/40" @click.self="picking = false">
        <div class="max-h-[85vh] w-[520px] max-w-full overflow-y-auto rounded-[8px] bg-white p-[22px] text-[13px]">
            <p class="mb-[12px] font-semibold">Selecciona los campos que necesites exportar en tu plantilla:</p>
            <label class="mb-[8px] flex items-center gap-2 font-semibold"><input v-model="allChosen" type="checkbox" /> Datos del producto</label>
            <label v-for="f in fields" :key="f.key" class="flex items-center gap-2 py-[3px] pl-[20px]">
                <input v-model="chosen" type="checkbox" :value="f.key" :disabled="f.required" /> {{ f.label }}<span v-if="f.required" class="text-muted">(obligatorio)</span>
            </label>
            <div class="mt-[16px] flex justify-end gap-3">
                <button type="button" class="s-btn s-btn-outline" @click="picking = false">Cancelar</button>
                <a :href="templateUrl" class="s-btn s-btn-primary" @click="picking = false">Descargar plantilla</a>
            </div>
        </div>
    </div>
</template>
