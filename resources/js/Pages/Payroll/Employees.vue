<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';

const props = defineProps({
    employees: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
});
const f = reactive({ q: '', estado: 'activos', ...props.filters });
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

    <div class="mt-[24px] flex items-center">
        <h2 class="text-[24px] font-semibold text-ink">Empleados</h2>
        <div class="ml-auto flex gap-2">
            <a href="/terceros/excel?tipo=empleado" class="s-btn s-btn-outline">Exportar lista de empleados</a>
            <Link href="/terceros/crear?tipo=empleado" class="s-btn s-btn-primary">Crear nuevo empleado</Link>
        </div>
    </div>

    <section class="s-card mt-[14px]">
        <div class="flex items-center gap-3 p-[16px]">
            <div class="relative w-[320px]">
                <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                <input v-model="f.q" class="s-input pl-9" placeholder="Buscar por nombre o identificación" @input="search" />
            </div>
            <select v-model="f.estado" class="s-input w-[160px]" @change="reload">
                <option value="activos">Activos</option>
                <option value="todos">Todos</option>
            </select>
        </div>
        <table class="s-table">
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
                <tr v-for="e in employees.data" :key="e.id" :class="{ 'opacity-60': !e.active }">
                    <td class="text-primary"><Link :href="`/nomina/empleados/${e.id}/editar`" class="hover:underline">{{ e.name }}</Link></td>
                    <td>{{ e.identification }}</td>
                    <td>{{ e.contract }}</td>
                    <td>{{ e.start }}</td>
                    <td>{{ e.end }}</td>
                    <td class="text-right">{{ e.salary ? money(e.salary) : '' }}</td>
                    <td>{{ e.type }}</td>
                    <td class="whitespace-nowrap">
                        <Link :href="`/nomina/empleados/${e.id}/editar`" class="s-btn-link" title="Contrato y nómina"><Icon icon="file-signature" /></Link>
                        <Link :href="`/terceros/${e.contact_id}/editar`" class="s-btn-link ml-2" title="Datos del tercero"><Icon icon="pen" /></Link>
                    </td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!employees.data.length" />
        <Pager :paginator="employees" />
    </section>
</template>
