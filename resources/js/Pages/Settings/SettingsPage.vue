<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

/* Pagina de configuracion declarativa (config/settings_pages.php). */
const props = defineProps({ page: String, def: Object, values: Object, secretsSet: Array });
const form = useForm({ ...props.values, action: 'save' });

const submit = (action = 'save') => {
    form.action = action;
    form.put(`/configuracion/ajustes/${props.page}`, { preserveScroll: true });
};
</script>

<template>
    <Head :title="def.title" />
    <PageHeader :title="def.title" back="/configuracion" />
    <p v-if="def.notice" class="mt-[12px] flex max-w-[900px] items-start gap-[10px] rounded-[6px] border border-[#b8e1ff] bg-primary-light px-[14px] py-[10px] text-[13px] text-ink">
        <Icon icon="circle-info" class="mt-[2px] text-primary" /> {{ def.notice }}
    </p>

    <form class="mt-[14px] max-w-[900px] space-y-[14px] text-[13px]" @submit.prevent="submit()">
        <section v-for="s in def.sections" :key="s.title" class="s-card">
            <h3 class="border-b border-[#edf0f2] px-[22px] py-[14px] text-[16px] font-semibold text-ink">{{ s.title }}</h3>
            <div class="grid grid-cols-2 gap-x-[24px] gap-y-[14px] px-[22px] py-[16px]">
                <div v-for="fl in s.fields" :key="fl.key" :class="{ 'col-span-2': fl.type === 'textarea' || fl.type === 'toggle' }">
                    <label v-if="fl.type === 'toggle'" class="flex items-center gap-[10px]">
                        <span class="s-switch"><input v-model="form[fl.key]" type="checkbox" /><span /></span> {{ fl.label }}
                    </label>
                    <template v-else>
                        <span class="s-label">{{ fl.label }}</span>
                        <input v-if="fl.type === 'readonly'" :value="form[fl.key]" class="s-input w-full" disabled />
                        <select v-else-if="fl.type === 'select'" v-model="form[fl.key]" class="s-input w-full">
                            <option value="">Selecciona una opción</option>
                            <option v-for="o in fl.options" :key="o" :value="o">{{ o }}</option>
                        </select>
                        <textarea v-else-if="fl.type === 'textarea'" v-model="form[fl.key]" rows="4" class="s-input h-auto w-full py-[8px]" />
                        <input v-else v-model="form[fl.key]" :type="fl.type === 'password' ? 'password' : fl.type === 'number' ? 'number' : fl.type === 'date' ? 'date' : 'text'"
                            class="s-input w-full" :placeholder="fl.type === 'password' && secretsSet.includes(fl.key) ? '•••••••• (guardada, deja vacío para no cambiar)' : ''" autocomplete="off" />
                        <p v-if="fl.help" class="mt-[3px] text-[11px] text-muted">{{ fl.help }}</p>
                    </template>
                </div>
            </div>
        </section>
        <div class="flex justify-end gap-[10px]">
            <button v-if="def.test" type="button" class="s-btn s-btn-outline" :disabled="form.processing" @click="submit('test')">{{ def.test }}</button>
            <button type="submit" class="s-btn s-btn-primary" :disabled="form.processing">Guardar</button>
        </div>
    </form>
</template>
