<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import PageHeader from '../../Components/PageHeader.vue';

/* Pagina de configuracion declarativa (config/settings_pages.php). */
const props = defineProps({ page: String, def: Object, values: Object, secretsSet: Array });
const form = useForm({ ...props.values, action: 'save' });
const inertia = usePage();
const apiToken = ref(null);
const copied = ref(false);
const keyInput = ref(null);

watch(() => props.values?.token_hint, (hint) => {
    if (hint) form.token_hint = hint;
});

watch(() => inertia.props.flash?.api_token, (token) => {
    if (!token) return;
    apiToken.value = token;
    copied.value = false;
}, { immediate: true });

const submit = (action = 'save') => {
    form.action = action;
    form.put(`/configuracion/ajustes/${props.page}`, { preserveScroll: true });
};

const copyToken = async () => {
    const value = apiToken.value ?? '';
    try {
        await navigator.clipboard.writeText(value);
    } catch {
        keyInput.value?.focus();
        keyInput.value?.select();
        document.execCommand('copy');
    }
    copied.value = true;
};
</script>

<template>
    <Head :title="def.title" />
    <PageHeader :title="def.title" back="/configuracion" />
    <p v-if="def.notice" class="mt-[12px] flex max-w-[900px] items-start gap-[10px] rounded-[6px] border border-[#bae6fd] bg-primary-light px-[14px] py-[10px] text-[13px] text-ink">
        <Icon icon="circle-info" class="mt-[2px] text-primary" />
        <span>{{ def.notice }} <a v-if="def.docs" :href="def.docs" target="_blank" rel="noopener" class="font-semibold text-primary underline">Ver documentación de la API</a></span>
    </p>

    <form class="mt-[14px] max-w-[900px] space-y-[14px] text-[13px]" @submit.prevent="submit()">
        <section v-for="s in def.sections" :key="s.title" class="s-card">
            <h3 class="border-b border-[#edf0f2] px-[22px] py-[14px] text-[16px] font-semibold text-ink">{{ s.title }}</h3>
            <div class="grid grid-cols-1 gap-x-[24px] gap-y-[14px] px-[14px] py-[16px] md:grid-cols-2 md:px-[22px]">
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

    <div v-if="apiToken" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="api-token-title">
        <div class="w-full max-w-[520px] rounded-[2px] bg-white px-[22px] pb-[16px] pt-[18px] text-[13px] text-[#333] shadow-lg">
            <h2 id="api-token-title" class="text-[15px] font-semibold text-ink">Llave de acceso</h2>
            <p class="mt-[8px] leading-[1.5]">Cópiala ahora. No se volverá a mostrar. Si cierras esta ventana sin copiarla, genera otra: la anterior queda revocada.</p>
            <input ref="keyInput" :value="apiToken" readonly class="s-input mt-[12px] w-full font-mono text-[12px]" @focus="$event.target.select()" />
            <div class="mt-[12px] flex justify-end gap-[8px]">
                <button type="button" class="s-btn s-btn-outline" @click="copyToken">{{ copied ? 'Copiada' : 'Copiar' }}</button>
                <button type="button" class="s-btn s-btn-primary" @click="apiToken = null">Cerrar</button>
            </div>
        </div>
    </div>
</template>
