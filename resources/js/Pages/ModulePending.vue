<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PageHeader from '../Components/PageHeader.vue';
import EmptyState from '../Components/EmptyState.vue';

const props = defineProps({
    title: { type: String, required: true },
    tabs: { type: Array, required: true },
});
const tab = ref(props.tabs[0]?.label);
</script>

<template>
    <Head :title="title" />
    <PageHeader :title="title" :tabs="tabs" :active="tab" @tab="tab = $event" />
    <section class="s-card mt-[18px]">
        <EmptyState title="Módulo en construcción" text="Esta pantalla se está construyendo." />
    </section>
</template>
