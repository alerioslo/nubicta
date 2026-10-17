<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';
import RowMenu from '../../Components/RowMenu.vue';

const props = defineProps({
    employees: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
});
const f = reactive({ q: '', ...props.filters });
const menu = ref(null);
const reload = () => router.get('/nomina/empleados', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
let t = null;
const search = () => {
    clearTimeout(t);
    t = setTimeout(reload, 350);
};
const money = (v) => '$ ' + Number(v).toLocaleString('es-CO', { maximumFractionDigits: 0 });
</script>

<template>
    <Head title="Empleados" />
    <PageHeader title="Nómina" :tabs="tabs" active="Empleados" />

    <h2 class="mt-[24px] text-[24px] font-semibold text-ink">Empleados</h2>

    <section class="s-card mt-[14px]" @click="menu = null">
        <div class="flex items-center gap-3 p-[16px]">
            <a href="/terceros/excel?tipo=empleado" class="s-btn s-btn-outline">Exportar lista de empleados</a>
            <div class="relative w-full sm:w-[290px]">
                <input v-model="f.q" class="s-input pr-9" placeholder="Buscar: Nombre, N° Id, N° Contrato" @input="search" />
                <button type="button" class="absolute right-0 top-0 grid h-full w-[24px] place-items-center rounded-r-[4px] bg-primary text-[11px] text-white" aria-label="Buscar" @click="reload"><Icon icon="magnifying-glass" /></button>
            </div>
            <Link href="/terceros/crear?tipo=empleado" class="s-btn s-btn-primary ml-auto">Crear nuevo empleado</Link>
        </div>
        <div class="s-table-wrap">
            <table class="sticky-actions s-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>N° Id</th>
                        <th>N° Contrato</th>
                        <th>Fecha inicio</th>
                        <th>Fecha fin</th>
                        <th class="text-right">Sueldo</th>
                        <th>T. Contrato</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in employees.data" :key="e.id">
                        <td class="text-primary"><Link :href="`/nomina/empleados/${e.id}/editar`" class="hover:underline">{{ e.name }}</Link></td>
                        <td>{{ e.identification }}</td>
                        <td>{{ e.contract }}</td>
                        <td>{{ e.start }}</td>
                        <td>{{ e.end }}</td>
                        <td class="text-right">{{ e.salary ? money(e.salary) : '' }}</td>
                        <td>{{ e.type }}</td>
                        <td class="whitespace-nowrap">
                            <RowMenu :open="menu === e.id" @close="menu = null">
                                <template #anchor>
                                    <Link :href="`/nomina/empleados/${e.id}/editar`" class="rounded-l-[4px] border border-[#c9ced3] px-[18px] py-[3px] text-[12px] text-muted hover:bg-[#f0f7fd]">Editar</Link>
                                    <button type="button" class="rounded-r-[4px] border border-l-0 border-[#c9ced3] px-[7px] text-muted hover:bg-[#f0f7fd]" aria-label="Más acciones" @click="menu = menu === e.id ? null : e.id"><Icon icon="caret-down" /></button>
                                </template>
                                <Link :href="`/nomina/empleados/${e.id}/editar`" class="block px-[14px] py-[7px] text-[13px] hover:bg-[#f0f7fd]">Contrato y nómina</Link>
                                <Link :href="`/terceros/${e.contact_id}/editar`" class="block px-[14px] py-[7px] text-[13px] hover:bg-[#f0f7fd]">Datos del tercero</Link>
                            </RowMenu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!employees.data.length" />
        <Pager :paginator="employees" />
    </section>
</template>
