<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import Field from '../../../Components/Field.vue';

const props = defineProps({
    role: { type: Object, default: null },
    matrix: { type: Array, required: true },
});

const form = useForm({
    name: props.role?.name ?? '',
    permissions: props.role?.permissions ?? [],
});

function toggleModule(mod, on) {
    const names = mod.actions.map((a) => a.name);
    form.permissions = on ? [...new Set([...form.permissions, ...names])] : form.permissions.filter((p) => !names.includes(p));
}
const allOn = (mod) => mod.actions.every((a) => form.permissions.includes(a.name));

function submit() {
    if (props.role) form.put(`/configuracion/perfiles/${props.role.id}`);
    else form.post('/configuracion/perfiles');
}
</script>

<template>
    <Head :title="role ? 'Editar perfil' : 'Crear perfil'" />
    <PageHeader :title="role ? 'Editar perfil' : 'Crear perfil'" back="/configuracion/perfiles">
        <template #actions>
            <Link href="/configuracion/perfiles" class="s-btn s-btn-outline">Cancelar</Link>
            <button type="button" class="s-btn s-btn-primary" :disabled="form.processing" @click="submit">Guardar</button>
        </template>
    </PageHeader>

    <form class="s-card mt-[18px] p-[22px]" @submit.prevent="submit">
        <Field label="Nombre del perfil" required :error="form.errors.name" class="max-w-[400px]">
            <input v-model="form.name" class="s-input" />
        </Field>

        <div class="s-table-wrap">
            <table class="s-table mt-6">
                <thead>
                    <tr>
                        <th class="w-[260px]">Módulo</th>
                        <th>Permisos</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in matrix" :key="m.key">
                        <td>
                            <label class="flex items-center gap-2 font-semibold text-ink">
                                <input type="checkbox" class="accent-primary" :checked="allOn(m)" @change="toggleModule(m, $event.target.checked)" />
                                {{ m.label }}
                            </label>
                        </td>
                        <td>
                            <div class="flex flex-wrap gap-x-6 gap-y-2">
                                <label v-for="a in m.actions" :key="a.name" class="flex items-center gap-2">
                                    <input v-model="form.permissions" type="checkbox" :value="a.name" class="accent-primary" /> {{ a.label }}
                                </label>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </form>
</template>
