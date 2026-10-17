<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';

defineProps({ roles: { type: Array, required: true } });
</script>

<template>
    <Head title="Perfiles" />
    <PageHeader
        title="Usuarios"
        back="/configuracion"
        :tabs="[{ label: 'Usuarios', href: '/configuracion/usuarios' }, { label: 'Perfiles', href: '/configuracion/perfiles' }]"
        active="Perfiles"
    />

    <section class="s-card mt-[18px]">
        <div class="flex px-[16px] py-[16px]">
            <Link href="/configuracion/perfiles/crear" class="s-btn s-btn-primary ml-auto w-[130px]">Crear perfil</Link>
        </div>
        <div class="s-table-wrap">
            <table class="sticky-actions s-table">
                <thead>
                    <tr>
                        <th>Perfil</th>
                        <th>Usuarios</th>
                        <th>Permisos</th>
                        <th class="w-[80px]">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in roles" :key="r.id">
                        <td class="font-semibold text-ink">{{ r.name }}</td>
                        <td>{{ r.users_count }}</td>
                        <td>{{ r.name === 'Administrador' ? 'Todos' : r.permissions_count }}</td>
                        <td>
                            <Link v-if="r.name !== 'Administrador'" :href="`/configuracion/perfiles/${r.id}/editar`" class="text-primary" title="Editar">
                                <Icon icon="pen" />
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
