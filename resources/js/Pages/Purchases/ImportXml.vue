<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

/* Importar compra o gasto desde el XML / ZIP de la factura electronica del proveedor. */
const props = defineProps({ preview: Object, ai: Boolean });
const matching = ref(false);
const sourceBadge = { 'Código': 's-badge-ok', Historial: 's-badge-ok', IA: 's-badge-warn' };

function matchWithAi() {
    matching.value = true;
    router.post(`/compras/importar-xml/${props.preview.inbox_id}/vincular-ia`, {}, { preserveScroll: true, onFinish: () => (matching.value = false) });
}
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const upload = useForm({ file: null });
const drag = ref(false);

function choose(file) {
    if (!file) return;
    upload.file = file;
    upload.post('/compras/importar-xml', { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <Head title="Importar compra o gasto desde XML" />
    <h1 class="pt-[10px] text-[24px] font-semibold text-ink">Importar compra o gasto desde XML o ZIP</h1>
    <p class="mt-[4px] text-[13px] text-muted">Carga la factura electrónica que te envió el proveedor (XML UBL 2.1, AttachedDocument o el ZIP recibido por correo).</p>

    <label class="s-card mt-[16px] flex cursor-pointer flex-col items-center justify-center gap-[10px] border-2 border-dashed px-[20px] py-[36px] text-center text-[13px]"
        :class="drag ? 'border-primary bg-primary-light' : 'border-line'"
        @dragover.prevent="drag = true" @dragleave="drag = false" @drop.prevent="drag = false; choose($event.dataTransfer.files[0])">
        <Icon icon="file-arrow-up" class="text-[34px] text-primary" />
        <span class="font-semibold text-ink">Arrastra aquí el archivo o haz clic para seleccionarlo</span>
        <span class="text-muted">{{ upload.file?.name ?? 'Formatos permitidos: .xml y .zip (máximo 10 MB)' }}</span>
        <input type="file" accept=".xml,.zip" class="hidden" @change="choose($event.target.files[0])" />
    </label>
    <p v-if="upload.errors.file" class="s-error mt-[6px]">{{ upload.errors.file }}</p>
    <p v-if="upload.processing" class="mt-[8px] text-[13px] text-muted">Leyendo archivo…</p>

    <template v-if="preview">
        <section class="s-card mt-[16px] p-[18px] text-[13px]">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-[12px] text-muted">{{ preview.kind }}</p>
                    <p class="text-[20px] font-semibold text-ink">{{ preview.number }}</p>
                    <p class="mt-[2px] break-all text-[11px] text-muted">CUFE: {{ preview.cufe || '—' }}</p>
                </div>
                <div class="text-right">
                    <p>Fecha: <b>{{ preview.date }}</b></p>
                    <p v-if="preview.due_date">Vence: <b>{{ preview.due_date }}</b></p>
                    <p>Moneda: <b>{{ preview.currency }}</b></p>
                </div>
            </div>
            <div class="mt-[16px] grid grid-cols-2 gap-[16px]">
                <div class="rounded-[6px] border border-line p-[12px]">
                    <p class="text-[12px] text-muted">Proveedor</p>
                    <p class="font-semibold text-ink">{{ preview.supplier.name }}</p>
                    <p>NIT {{ preview.supplier.nit }}</p>
                    <span class="s-badge mt-[6px]" :class="preview.supplier.exists ? 's-badge-ok' : 's-badge-warn'">{{ preview.supplier.exists ? 'Proveedor registrado' : 'Proveedor nuevo: se creará al continuar' }}</span>
                </div>
                <div class="rounded-[6px] border border-line p-[12px]">
                    <p class="text-[12px] text-muted">Adquiriente</p>
                    <p class="font-semibold text-ink">{{ preview.customer.name }}</p>
                    <p>NIT {{ preview.customer.nit }}</p>
                </div>
            </div>
        </section>

        <section class="s-card mt-[14px] overflow-hidden">
            <div class="s-table-wrap">
                <table class="s-table text-[13px]">
                    <thead><tr><th>Código</th><th>Descripción</th><th>Producto en el ERP</th><th class="text-right">Cantidad</th><th class="text-right">Valor unitario</th><th class="text-right">% IVA</th><th class="text-right">Impuesto</th><th class="text-right">Subtotal</th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in preview.lines" :key="i">
                            <td>{{ l.code }}</td><td>{{ l.description }}</td>
                            <td>
                                <template v-if="preview.matches[i]">
                                    <span class="text-ink">{{ preview.matches[i].label }}</span>
                                    <span class="s-badge ml-[6px]" :class="sourceBadge[preview.matches[i].source]" :title="preview.matches[i].reason || ''">
                                        {{ preview.matches[i].source }}<template v-if="preview.matches[i].source === 'IA'"> {{ preview.matches[i].confidence }}%</template>
                                    </span>
                                </template>
                                <span v-else class="text-muted">Sin vincular</span>
                            </td>
                            <td class="text-right">{{ l.quantity }}</td><td class="text-right">{{ money(l.price) }}</td>
                            <td class="text-right">{{ l.tax_percent }}</td><td class="text-right">{{ money(l.tax) }}</td><td class="text-right">{{ money(l.subtotal) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <dl class="ml-auto w-[300px] space-y-[6px] px-[18px] py-[14px] text-[13px]">
                <div class="flex justify-between"><dt>Subtotal</dt><dd>{{ money(preview.totals.subtotal) }}</dd></div>
                <div class="flex justify-between"><dt>Descuentos</dt><dd>{{ money(preview.totals.discount) }}</dd></div>
                <div class="flex justify-between"><dt>Impuestos</dt><dd>{{ money(preview.totals.tax) }}</dd></div>
                <div class="flex justify-between border-t border-line pt-[6px] text-[15px] font-bold"><dt>Total a pagar</dt><dd>{{ money(preview.totals.total) }}</dd></div>
            </dl>
        </section>
        <div class="mt-[14px] flex justify-end gap-[10px]">
            <Link href="/compras" class="s-btn s-btn-outline">Cancelar</Link>
            <span v-if="preview.causada" class="s-badge s-badge-ok self-center">Esta factura ya fue causada</span>
            <button v-if="ai && !preview.causada && preview.lines.some((l, i) => !preview.matches[i])" type="button" class="s-btn s-btn-outline" :disabled="matching" @click="matchWithAi">
                <Icon icon="wand-magic-sparkles" class="mr-[6px]" />{{ matching ? 'Vinculando con IA…' : 'Vincular productos con IA' }}
            </button>
            <Link v-if="!preview.causada" :href="`/compras/facturas/crear?bandeja=${preview.inbox_id}`" class="s-btn s-btn-primary">Continuar a la factura de compra</Link>
        </div>
    </template>
</template>
