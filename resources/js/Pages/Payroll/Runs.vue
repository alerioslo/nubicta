<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';

const props = defineProps({
    runs: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
});
const q = ref(props.filters.q ?? '');
const search = () => router.get('/nomina', q.value ? { q: q.value } : {}, { preserveState: true, replace: true });
const money = (v) => Number(v).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <Head title="Nómina" />
    <PageHeader title="Nómina" :tabs="tabs" active="Nóminas" />

    <div class="mt-[30px] flex items-center px-[22px]">
        <h2 class="text-[24px] font-semibold text-primary">Mis nóminas</h2>
        <div class="ml-auto flex gap-2">
            <Link href="/ayuda#nomina" class="s-btn s-btn-outline">Ver tutoriales</Link>
            <Link href="/nomina/crear" class="s-btn s-btn-green px-5"><Icon icon="plus" /> Crear nómina</Link>
        </div>
    </div>

    <section class="s-card md:mx-[10px] mt-[18px] p-[20px]">
        <div class="mb-4 flex items-center">
            <h3 class="text-[16px] font-bold text-ink">Resultados</h3>
            <form class="ml-auto flex w-full items-center sm:w-[300px] rounded-full bg-[#eef1f4]" @submit.prevent="search">
                <Icon icon="magnifying-glass" class="ml-3 text-muted" />
                <input v-model="q" class="flex-1 bg-transparent px-2 py-[7px] text-[12px] outline-none" placeholder="Buscar por Estado / Nombre / Periodo de pago" />
                <button type="submit" class="s-btn-green rounded-full px-4 py-[7px] text-[13px]">Buscar</button>
            </form>
        </div>
        <div class="s-table-wrap">
            <table class="sticky-actions w-full border-collapse">
                <thead>
                    <tr class="bg-primary text-left text-[14px] font-semibold text-white">
                        <th class="px-3 py-[9px]">Estado</th>
                        <th class="px-3">Nombre</th>
                        <th class="px-3">Tipo liquidación</th>
                        <th class="px-3">Periodo de pago</th>
                        <th class="px-3 text-right">Total nómina</th>
                        <th class="px-3 text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in runs.data" :key="r.id" class="border-b border-line">
                        <td class="border-l-[3px] px-3 py-[9px] font-semibold" :class="r.done ? 'border-l-[#7ac142] text-[#5a9e2b]' : 'border-l-[#ffb300] text-[#a86b00]'">{{ r.status }}</td>
                        <td class="px-3 font-semibold text-[#5e6870] underline"><Link :href="`/nomina/${r.id}`">{{ r.name }}</Link></td>
                        <td class="px-3">{{ r.type }}</td>
                        <td class="px-3">{{ r.period }}</td>
                        <td class="px-3 text-right">{{ money(r.total) }}</td>
                        <td class="px-3 text-center text-muted"><Link :href="`/nomina/${r.id}`" title="Ver nómina"><Icon icon="file-lines" /></Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!runs.data.length" />
        <Pager :paginator="runs" />
    </section>
</template>
