<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import Field from '../../../Components/Field.vue';

const props = defineProps({
    user: { type: Object, default: null },
    roles: { type: Array, required: true },
});

const form = useForm({
    name: props.user?.name ?? '',
    identification: props.user?.identification ?? '',
    email: props.user?.email ?? '',
    phone: props.user?.phone ?? '',
    is_seller: props.user?.is_seller ?? false,
    active: props.user?.active ?? true,
    password: '',
    roles: props.user?.roles ?? [],
});

function submit() {
    if (props.user) form.put(`/configuracion/usuarios/${props.user.id}`);
    else form.post('/configuracion/usuarios');
}
</script>

<template>
    <Head :title="user ? 'Editar usuario' : 'Crear usuario'" />
    <PageHeader :title="user ? 'Editar usuario' : 'Crear usuario'" back="/configuracion/usuarios">
        <template #actions>
            <Link href="/configuracion/usuarios" class="s-btn s-btn-outline">Cancelar</Link>
            <button type="button" class="s-btn s-btn-primary" :disabled="form.processing" @click="submit">Guardar</button>
        </template>
    </PageHeader>

    <form class="s-card mt-[18px] max-w-[900px] p-[22px]" @submit.prevent="submit">
        <div class="grid grid-cols-2 gap-4">
            <Field label="Nombre completo" required :error="form.errors.name"><input v-model="form.name" class="s-input" /></Field>
            <Field label="Identificación" :error="form.errors.identification"><input v-model="form.identification" class="s-input" /></Field>
            <Field label="Correo electrónico" required :error="form.errors.email"><input v-model="form.email" type="email" class="s-input" /></Field>
            <Field label="Teléfono" :error="form.errors.phone"><input v-model="form.phone" class="s-input" /></Field>
            <Field :label="user ? 'Nueva contraseña (opcional)' : 'Contraseña'" :required="!user" :error="form.errors.password">
                <input v-model="form.password" type="password" autocomplete="new-password" class="s-input" />
            </Field>
            <div class="flex items-end gap-6 pb-2">
                <label class="flex items-center gap-2"><input v-model="form.is_seller" type="checkbox" class="accent-primary" /> Es vendedor</label>
                <label class="flex items-center gap-2"><input v-model="form.active" type="checkbox" class="accent-primary" /> Activo</label>
            </div>
        </div>

        <div class="mt-6">
            <span class="s-label">Perfil<span class="req">*</span></span>
            <div class="flex flex-wrap gap-3">
                <label v-for="r in roles" :key="r" class="flex cursor-pointer items-center gap-2 rounded-[6px] border px-3 py-2"
                    :class="form.roles.includes(r) ? 'border-primary bg-primary-light' : 'border-line'">
                    <input v-model="form.roles" type="checkbox" :value="r" class="accent-primary" /> {{ r }}
                </label>
            </div>
            <p v-if="form.errors.roles" class="s-error">{{ form.errors.roles }}</p>
        </div>
    </form>
</template>
