<script setup>
import { Head } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import StatementPanel from '../../Components/StatementPanel.vue';

defineProps({
    side: { type: String, required: true },
    cfg: { type: Object, required: true },
    contact: { type: Object, required: true },
    docs: { type: Array, required: true },
    todos: { type: Boolean, default: false },
    tabs: { type: Array, required: true },
});
</script>

<template>
    <Head :title="`Estado de cuenta ${contact.name}`" />
    <PageHeader :title="`Estado de cuenta`" :back="cfg.url" />
    <StatementPanel :side="side" :url="cfg.url" :contact="contact" :docs="docs" :todos="todos" :base-url="`${cfg.url}/${contact.id}`" />
</template>
