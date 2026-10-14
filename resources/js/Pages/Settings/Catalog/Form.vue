<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import Field from '../../../Components/Field.vue';
import AccountPicker from '../../../Components/AccountPicker.vue';

const props = defineProps({
    catalog: { type: String, required: true },
    title: { type: String, required: true },
    listTitle: { type: String, required: true },
    fields: { type: Array, required: true },
    values: { type: Object, required: true },
    accountLabels: { type: Object, default: () => ({}) },
    recordId: { type: Number, default: null },
});

const form = useForm({ ...props.values });
const labels = reactive(
    Object.fromEntries(props.fields.filter((f) => f.type === 'account').map((f) => [f.key, props.accountLabels[props.values[f.key]] ?? ''])),
);

const sections = computed(() => {
    const groups = {};
    for (const f of props.fields) {
        const s = f.section ?? 'General';
        (groups[s] ??= []).push(f);
    }
    return groups;
});

const listUrl = `/configuracion/${props.catalog}`;
function submit() {
    if (props.recordId) form.put(`${listUrl}/${props.recordId}`);
    else form.post(listUrl);
}
</script>

<template>
    <Head :title="title" />
    <PageHeader :title="title" :back="listUrl">
        <template #actions>
            <Link :href="listUrl" class="s-btn s-btn-outline">Cancelar</Link>
            <button type="button" class="s-btn s-btn-primary" :disabled="form.processing" @click="submit">Guardar</button>
        </template>
    </PageHeader>

    <form class="mt-[18px] flex max-w-[1000px] flex-col gap-[18px]" @submit.prevent="submit">
        <section v-for="(items, name) in sections" :key="name" class="s-card p-[22px]">
            <h2 class="mb-4 text-[15px] font-bold text-ink">{{ name }}</h2>
            <div class="grid grid-cols-2 gap-4">
                <template v-for="f in items" :key="f.key">
                    <label v-if="f.type === 'checkbox'" class="flex items-center gap-2 self-end pb-2">
                        <input v-model="form[f.key]" type="checkbox" class="accent-primary" /> {{ f.label }}
                    </label>
                    <div v-else-if="f.type === 'multiselect'" class="col-span-2">
                        <span class="s-label">{{ f.label }}</span>
                        <div class="flex flex-wrap gap-x-6 gap-y-2">
                            <label v-for="(n, k) in f.options" :key="k" class="flex items-center gap-2">
                                <input v-model="form[f.key]" type="checkbox" :value="k" class="accent-primary" /> {{ n }}
                            </label>
                        </div>
                    </div>
                    <Field v-else :label="f.label" :required="f.required" :error="form.errors[f.key]" :class="{ 'col-span-2': f.type === 'textarea' }">
                        <select v-if="f.type === 'select'" v-model="form[f.key]" class="s-input" :class="{ 'is-invalid': form.errors[f.key] }">
                            <option v-for="(n, k) in f.options" :key="k" :value="k">{{ n }}</option>
                        </select>
                        <AccountPicker
                            v-else-if="f.type === 'account'"
                            v-model="form[f.key]"
                            v-model:label="labels[f.key]"
                            :invalid="!!form.errors[f.key]"
                        />
                        <textarea v-else-if="f.type === 'textarea'" v-model="form[f.key]" rows="3" class="s-input" />
                        <input
                            v-else
                            v-model="form[f.key]"
                            :type="f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : f.secret ? 'password' : 'text'"
                            :step="f.step ?? (f.type === 'number' ? '1' : undefined)"
                            :placeholder="f.secret && recordId ? 'Dejar vacío para no cambiar' : ''"
                            class="s-input"
                            :class="{ 'is-invalid': form.errors[f.key] }"
                        />
                    </Field>
                </template>
            </div>
        </section>
    </form>
</template>
