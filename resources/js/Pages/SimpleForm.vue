<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../Components/PageHeader.vue';
import Lookup from '../Components/Lookup.vue';
import AccountPicker from '../Components/AccountPicker.vue';

/*
 * Formulario generico. fields: [{name, label, type: text|textarea|date|number|money|select|lookup|account|check|year|month,
 * options, url, required, help, span}]. grid: {name, label, columns: [...mismos tipos], add}. preview: {columns, rows, totals}.
 */
const props = defineProps({
    page: { type: Object, required: true },
    tabs: { type: Array, default: null },
    initial: { type: Object, default: () => ({}) },
    preview: { type: Object, default: null },
});

const blankRow = () => Object.fromEntries((props.page.grid?.columns ?? []).map((c) => [c.name, c.type === 'number' || c.type === 'money' ? 0 : null]));
const data = { ...Object.fromEntries((props.page.fields ?? []).map((f) => [f.name, f.type === 'check' ? false : null])), ...props.initial };
if (props.page.grid) data[props.page.grid.name] = props.initial[props.page.grid.name]?.length ? props.initial[props.page.grid.name] : [blankRow()];
const form = useForm(data);

const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const thisYear = new Date().getFullYear();
const years = Array.from({ length: 8 }, (_, i) => thisYear - i);
const opts = (f) => (f.type === 'year' ? years.map((y) => [y, y]) : f.type === 'month' ? MONTHS.map((m, i) => [i + 1, m]) : (f.options ?? []).map((o) => (Array.isArray(o) ? o : [o, o])));
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const gridError = computed(() => Object.entries(form.errors).find(([k]) => k.startsWith(`${props.page.grid?.name}.`) || k === props.page.grid?.name)?.[1]);

function submit(extra = {}) {
    const opts = { preserveScroll: true };
    form.transform((d) => ({ ...d, ...extra }));
    if (props.page.method === 'get') form.get(props.page.action, { ...opts, preserveState: true });
    else if (props.page.method === 'put') form.put(props.page.action, opts);
    else form.post(props.page.action, opts);
}
function confirmSubmit(b) {
    if (b.confirm && !window.confirm(b.confirm)) return;
    submit(b.data ?? {});
}
</script>

<template>
    <Head :title="page.title" />
    <PageHeader v-if="tabs" :title="page.module ?? page.title" :tabs="tabs" :active="page.tab" />
    <div class="px-[22px] pt-[22px]">
        <Link v-if="page.back" :href="page.back" class="text-[13px] text-primary hover:underline"><Icon icon="arrow-left" /> Volver</Link>
        <h1 class="mt-1 text-[22px] font-semibold text-primary">{{ page.title }}</h1>
        <p v-if="page.subtitle" class="text-[13px] text-muted">{{ page.subtitle }}</p>
        <ol v-if="page.steps" class="mt-[12px] flex gap-[6px]">
            <li v-for="(st, i) in page.steps" :key="st" class="flex h-[34px] w-[250px] items-center gap-[8px] pl-[10px] text-[13px] text-white [clip-path:polygon(0_0,93%_0,100%_50%,93%_100%,0_100%)]"
                :class="i + 1 === page.step ? 'bg-[#3d8bc9]' : 'bg-[#9a9a9a]'"><b class="text-[20px]">{{ i + 1 }}</b> {{ st }}</li>
        </ol>
    </div>
    <div v-if="page.info" class="mx-[22px] mt-[14px] flex gap-3 rounded border border-[#b9dcf3] bg-[#eaf5fc] px-4 py-3 text-[13px] text-ink">
        <Icon icon="circle-info" class="mt-[2px] text-primary" />
        <p class="whitespace-pre-line">{{ page.info }}</p>
    </div>

    <form class="s-card mt-[16px] p-[20px]" @submit.prevent="submit()">
        <div class="grid gap-x-5 gap-y-4 md:grid-cols-2 xl:grid-cols-3">
            <label v-for="f in page.fields ?? []" :key="f.name" class="block" :class="f.span === 'full' ? 'md:col-span-2 xl:col-span-3' : ''">
                <span v-if="f.type !== 'check'" class="s-label">{{ f.label }}<span v-if="f.required" class="req">*</span></span>
                <textarea v-if="f.type === 'textarea'" v-model="form[f.name]" rows="3" class="s-input h-auto" :class="{ 'is-invalid': form.errors[f.name] }" />
                <select v-else-if="['select', 'year', 'month'].includes(f.type)" v-model="form[f.name]" class="s-input" :class="{ 'is-invalid': form.errors[f.name] }">
                    <option v-if="!f.required" :value="null">Seleccionar</option>
                    <option v-for="[v, l] in opts(f)" :key="v" :value="v">{{ l }}</option>
                </select>
                <Lookup v-else-if="f.type === 'lookup'" v-model="form[f.name]" v-model:label="form[`${f.name}_label`]" :url="f.url" :invalid="!!form.errors[f.name]" :placeholder="f.placeholder ?? 'Buscar'" />
                <AccountPicker v-else-if="f.type === 'account'" v-model="form[f.name]" v-model:label="form[`${f.name}_label`]" :invalid="!!form.errors[f.name]" />
                <span v-else-if="f.type === 'check'" class="flex items-center gap-2 pt-[22px] text-[13px]"><input v-model="form[f.name]" type="checkbox" /> {{ f.label }}</span>
                <input v-else v-model="form[f.name]" :type="['number', 'money'].includes(f.type) ? 'number' : f.type || 'text'" :step="f.type === 'money' ? '0.01' : 'any'"
                    class="s-input" :class="{ 'is-invalid': form.errors[f.name] }" :placeholder="f.placeholder" />
                <p v-if="f.help" class="mt-1 text-[11px] text-muted">{{ f.help }}</p>
                <p v-if="form.errors[f.name]" class="s-error">{{ form.errors[f.name] }}</p>
            </label>
        </div>

        <div v-if="page.grid" class="mt-6">
            <h3 class="mb-2 text-[14px] font-bold text-ink">{{ page.grid.label }}</h3>
            <div class="overflow-x-auto">
                <table class="s-table w-full">
                    <thead><tr><th class="w-8">#</th><th v-for="c in page.grid.columns" :key="c.name" :class="['number', 'money'].includes(c.type) && 'text-right'">{{ c.label }}</th><th class="w-8" /></tr></thead>
                    <tbody>
                        <tr v-for="(r, i) in form[page.grid.name]" :key="i">
                            <td>{{ i + 1 }}</td>
                            <td v-for="c in page.grid.columns" :key="c.name" :class="c.width">
                                <Lookup v-if="c.type === 'lookup'" v-model="r[c.name]" v-model:label="r[`${c.name}_label`]" :url="c.url" input-class="s-input min-w-[260px]" />
                                <AccountPicker v-else-if="c.type === 'account'" v-model="r[c.name]" v-model:label="r[`${c.name}_label`]" />
                                <select v-else-if="c.type === 'select'" v-model="r[c.name]" class="s-input"><option v-for="o in c.options" :key="o" :value="o">{{ o }}</option></select>
                                <span v-else-if="c.type === 'static'" class="block whitespace-nowrap" :class="c.money && 'text-right'">{{ c.money ? money(r[c.name]) : r[c.name] }}</span>
                                <input v-else v-model="r[c.name]" :type="['number', 'money'].includes(c.type) ? 'number' : 'text'" step="any" class="s-input min-w-[90px]" :class="['number', 'money'].includes(c.type) && 'text-right'" />
                            </td>
                            <td><button type="button" class="text-muted hover:text-[#c62828]" title="Eliminar fila" @click="form[page.grid.name].splice(i, 1)"><Icon icon="trash-can" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button type="button" class="mt-2 text-[13px] font-semibold text-primary hover:underline" @click="form[page.grid.name].push(blankRow())"><Icon icon="circle-plus" /> {{ page.grid.add ?? 'Agregar fila' }}</button>
            <p v-if="gridError" class="s-error">{{ gridError }}</p>
        </div>

        <div class="mt-6 flex justify-end gap-2">
            <template v-for="l in page.links ?? []" :key="l.href">
                <Link v-if="l.inertia" :href="l.href" class="s-btn s-btn-outline">{{ l.label }}</Link>
                <a v-else :href="l.href" class="s-btn s-btn-outline"><Icon icon="download" /> {{ l.label }}</a>
            </template>
            <Link v-if="page.back && page.method !== 'get'" :href="page.back" class="s-btn s-btn-outline">Cancelar</Link>
            <template v-if="page.buttons">
                <button v-for="b in page.buttons" :key="b.label" type="button" class="s-btn" :class="b.style === 'outline' ? 's-btn-outline' : 's-btn-primary'" :disabled="form.processing" @click="confirmSubmit(b)">{{ b.label }}</button>
            </template>
            <button v-else type="submit" class="s-btn s-btn-primary" :disabled="form.processing">{{ page.submit ?? 'Guardar' }}</button>
        </div>
    </form>

    <section v-if="preview" class="s-card mt-[16px]">
        <h3 v-if="preview.title" class="px-[16px] pt-[14px] text-[15px] font-bold text-ink">{{ preview.title }}</h3>
        <p v-if="preview.text" class="px-[16px] pt-1 text-[12.5px] text-muted">{{ preview.text }}</p>
        <div class="overflow-x-auto">
            <table class="s-table mt-2 w-full">
                <thead><tr><th v-for="(c, j) in preview.columns" :key="j" :class="preview.money?.includes(j) && 'text-right'">{{ c }}</th></tr></thead>
                <tbody>
                    <tr v-for="(r, i) in preview.rows" :key="i">
                        <td v-for="(v, j) in r" :key="j" :class="preview.money?.includes(j) && 'text-right'">{{ preview.money?.includes(j) ? money(v) : v }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="preview.totals">
                    <tr class="font-bold"><td v-for="(v, j) in preview.totals" :key="j" class="px-[14px] py-[8px]" :class="preview.money?.includes(j) && 'text-right'">{{ preview.money?.includes(j) && v !== '' ? money(v) : v }}</td></tr>
                </tfoot>
            </table>
        </div>
        <p v-if="!preview.rows.length" class="p-4 text-[13px] text-muted">No hay movimientos para mostrar.</p>
    </section>
</template>
