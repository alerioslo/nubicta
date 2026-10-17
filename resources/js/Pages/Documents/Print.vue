<script setup>
import { onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { printWhenReady } from '../../composables/print';
import DocumentSheet from '../../Components/DocumentSheet.vue';
import ReceiptSheet from '../../Components/ReceiptSheet.vue';
import JournalSheet from '../../Components/JournalSheet.vue';
import PurchaseSheet from '../../Components/PurchaseSheet.vue';

/* Representacion grafica imprimible (Imprimir / Guardar como PDF del navegador). Con docs[] imprime un lote. */
defineOptions({ layout: null });
const props = defineProps({ doc: Object, docs: Array, company: Object, resolution: Object, bare: Boolean });
const list = props.docs ?? [{ doc: props.doc, resolution: props.resolution }];
const print = () => window.print();
onMounted(printWhenReady);
</script>

<template>
    <Head :title="doc?.name ?? `Impresión de ${list.length} comprobantes`" />
    <div v-if="!bare" class="print-bar"><button type="button" @click="print">Imprimir / Guardar PDF</button></div>
    <template v-for="d in list" :key="d.doc.id ?? d.doc.name">
        <ReceiptSheet v-if="d.doc.kind === 'recibos'" :doc="d.doc" :company="company" class="mb-[30px]" />
        <JournalSheet v-else-if="d.doc.kind === 'comprobantes'" :doc="d.doc" :company="company" class="print-purchase" />
        <PurchaseSheet v-else-if="d.doc.kind === 'compras'" :doc="d.doc" :company="company" class="print-purchase" />
        <DocumentSheet v-else :doc="d.doc" :company="company" :resolution="d.resolution" />
    </template>
</template>

<style scoped>
.print-bar { padding: 10px; text-align: center; }
.print-bar button { background: #00a6e0; color: #fff; border: 0; padding: 6px 16px; border-radius: 2px; cursor: pointer; }
.print-purchase { width: 980px; margin: 0 auto 30px; }
@media print { .print-bar { display: none; } .print-purchase { width: auto; margin: 0; } }
</style>
