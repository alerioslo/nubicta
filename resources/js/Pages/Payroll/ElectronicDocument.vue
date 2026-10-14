<script setup>
import { onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PayrollSheet from '../../Components/PayrollSheet.vue';

/* Detalle e impresion del documento soporte de pago de nomina electronica (o su nota de ajuste). */
const props = defineProps({ doc: Object, company: Object, print: Boolean });
const doPrint = () => window.print();
onMounted(() => props.print && setTimeout(doPrint, 400));
</script>

<template>
    <Head :title="`${doc.type} ${doc.consecutive}`" />
    <div class="mx-auto mt-[16px] flex max-w-[760px] items-center gap-[10px] print:hidden">
        <Link href="/nomina/documentos" class="text-primary"><Icon icon="chevron-left" /> Documentos electrónicos</Link>
        <button type="button" class="s-btn s-btn-outline ml-auto" @click="doPrint"><Icon icon="print" /> Imprimir</button>
        <a :href="`/nomina/documentos/${doc.id}/pdf`" class="s-btn s-btn-outline"><Icon icon="file-pdf" /> PDF</a>
        <a v-if="doc.xml" :href="`/nomina/documentos/${doc.id}/xml`" class="s-btn s-btn-outline"><Icon icon="file-code" /> XML</a>
    </div>
    <PayrollSheet :doc="doc" :company="company" />
</template>
