<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import Pager from '../../../Components/Pager.vue';
import EmptyState from '../../../Components/EmptyState.vue';

const props = defineProps({
    users: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const q = ref(props.filters.q ?? '');
let t = null;
function search() {
    clearTimeout(t);
    t = setTimeout(() => router.get('/configuracion/usuarios', { q: q.value || undefined }, { preserveState: true, replace: true }), 350);
}
</script>

<template>
    <Head title="Usuarios" />
    <PageHeader
        title="Usuarios"
        back="/configuracion"
        :tabs="[{ label: 'Usuarios', href: '/configuracion/usuarios' }, { label: 'Perfiles', href: '/configuracion/perfiles' }]"
        active="Usuarios"
    />

    <section class="s-card mt-[18px]">
        <div class="flex items-center gap-3 px-[16px] py-[16px]">
            <div class="relative w-[300px]">
                <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                <input v-model="q" class="s-input pl-9" placeholder="Buscar por nombre, correo o identificación" @input="search" />
            </div>
            <Link href="/configuracion/usuarios/crear" class="s-btn s-btn-primary ml-auto w-[130px]">Crear usuario</Link>
        </div>

        <table class="s-table">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Correo</th>
                    <th>Identificación</th>
                    <th>Perfil</th>
                    <th>Vendedor</th>
                    <th>Último ingreso</th>
                    <th>Estado</th>
                    <th class="w-[80px]">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="u in users.data" :key="u.id">
                    <td class="font-semibold text-ink">{{ u.name }}</td>
                    <td>{{ u.email }}</td>
                    <td>{{ u.identification }}</td>
                    <td>{{ u.roles.join(', ') }}</td>
                    <td>{{ u.is_seller ? 'Sí' : 'No' }}</td>
                    <td>{{ u.last_login_at ?? '—' }}</td>
                    <td><span class="s-badge" :class="u.active ? 's-badge-ok' : 's-badge-off'">{{ u.active ? 'Activo' : 'Inactivo' }}</span></td>
                    <td>
                        <Link :href="`/configuracion/usuarios/${u.id}/editar`" class="text-primary" title="Editar"><Icon icon="pen" /></Link>
                    </td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!users.data.length" />
        <Pager :paginator="users" />
    </section>
</template>
