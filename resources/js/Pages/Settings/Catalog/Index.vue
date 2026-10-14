<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import Pager from '../../../Components/Pager.vue';
import EmptyState from '../../../Components/EmptyState.vue';

const props = defineProps({
    catalog: { type: String, required: true },
    title: { type: String, required: true },
    singular: { type: String, required: true },
    columns: { type: Array, required: true },
    filter: { type: Object, default: null },
    rows: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const f = reactive({ q: props.filters.q ?? '', f: props.filters.f ?? '', estado: props.filters.estado ?? '' });
let t = null;
function apply(delay = 0) {
    clearTimeout(t);
    t = setTimeout(() => {
        const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v));
        router.get(`/configuracion/${props.catalog}`, params, { preserveState: true, replace: true });
    }, delay);
}

function fmt(col, value) {
    if (col.format === 'percent') return value === null || value === undefined ? '' : `${Number(value).toLocaleString('es-CO', { maximumFractionDigits: 3 })}%`;
    if (col.format === 'yesno') return value ? 'Sí' : 'No';
    if (col.format === 'map') return col.map?.[value] ?? value;
    return value ?? '';
}
</script>

<template>
    <Head :title="title" />
    <PageHeader :title="title" back="/configuracion" />

    <section class="s-card mt-[18px]">
        <div class="flex items-center gap-3 p-[16px]">
            <div class="relative w-[300px]">
                <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                <input v-model="f.q" class="s-input pl-9" placeholder="Buscar" @input="apply(350)" />
            </div>
            <select v-if="filter" v-model="f.f" class="s-input w-[230px]" @change="apply()">
                <option value="">{{ filter.label }}: todos</option>
                <option v-for="(n, k) in filter.options" :key="k" :value="k">{{ k }} - {{ n }}</option>
            </select>
            <select v-model="f.estado" class="s-input w-[140px]" @change="apply()">
                <option value="">Todos</option>
                <option value="activos">Activos</option>
                <option value="inactivos">Inactivos</option>
            </select>
            <Link :href="`/configuracion/${catalog}/crear`" class="s-btn s-btn-primary ml-auto">Crear {{ singular }}</Link>
        </div>

        <table class="s-table">
            <thead>
                <tr>
                    <th v-for="c in columns" :key="c.key">{{ c.label }}</th>
                    <th class="w-[80px]">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows.data" :key="r.id">
                    <td v-for="(c, i) in columns" :key="c.key" :class="{ 'font-semibold text-ink': i === 0 }">
                        <span v-if="c.format === 'status'" class="s-badge" :class="r[c.key] ? 's-badge-ok' : 's-badge-off'">
                            {{ r[c.key] ? 'Activo' : 'Inactivo' }}
                        </span>
                        <template v-else>{{ fmt(c, r[c.key]) }}</template>
                    </td>
                    <td>
                        <Link :href="`/configuracion/${catalog}/${r.id}/editar`" class="text-primary" title="Editar"><Icon icon="pen" /></Link>
                    </td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!rows.data.length" />
        <Pager :paginator="rows" />
    </section>
</template>
