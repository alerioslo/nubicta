<script setup>
import PayrollSheet from '../../Components/PayrollSheet.vue';

/* Vista sin menu que Chromium imprime a PDF (descarga y envio por correo). */
defineOptions({ layout: null });
defineProps({ doc: Object, company: Object });
</script>

<template>
    <PayrollSheet :doc="doc" :company="company" class="!mt-0 !shadow-none" />
</template>
