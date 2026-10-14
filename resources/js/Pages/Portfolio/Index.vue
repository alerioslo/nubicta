<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';

const props = defineProps({
    side: { type: String, required: true },
    cfg: { type: Object, required: true },
    rows: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, required: true },
});

const f = reactive({ ...props.filters });
const open = ref(false);
function reload() {
    router.get(props.cfg.url, Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
}
let t = null;
function search() {
    clearTimeout(t);
    t = setTimeout(reload, 350);
}
const SALDO = { todos: 'Todos', con_saldo: 'Con saldo', vencido: 'Con saldo vencido' };
const ESTADO = { activo: 'Activo', inactivo: 'Inactivo', todos: 'Todos' };
const party = props.side === 'clientes' ? 'cliente' : 'proveedor';
function clear() {
    Object.assign(f, { q: '', saldo: 'todos', estado: 'activo' });
    reload();
}
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <Head :title="cfg.tab" />
    <PageHeader :title="side === 'clientes' ? 'Ventas' : 'Compras y gastos'" :tabs="tabs" :active="cfg.tab" />

    <section class="s-card mt-[18px]">
        <div class="px-[16px] pt-[16px]">
            <span class="s-label font-normal text-muted">Búsqueda</span>
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative w-[300px]">
                    <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                    <input v-model="f.q" class="s-input pl-9" placeholder="Buscar por nombre o identificación" @input="search" />
                </div>
                <button type="button" class="s-btn s-btn-outline" @click="open = !open"><Icon icon="sliders" /> Más filtros</button>
                <div class="ml-auto flex gap-2">
                    <a :href="`/terceros/excel?tipo=${party}`" class="s-btn s-btn-outline"><Icon icon="download" /> Descargar</a>
                    <Link href="/movimientos" class="s-btn s-btn-outline">Ver reportes <Icon icon="chevron-down" /></Link>
                    <Link :href="cfg.create" class="s-btn s-btn-primary">{{ cfg.create_label }}</Link>
                </div>
            </div>
            <div v-if="open" class="mt-3 flex flex-wrap items-end gap-3">
                <label><span class="s-label">Saldo de cartera</span>
                    <select v-model="f.saldo" class="s-input w-[190px]" @change="reload">
                        <option v-for="(n, k) in SALDO" :key="k" :value="k">{{ n }}</option>
                    </select>
                </label>
                <label><span class="s-label">Estado de {{ party }}</span>
                    <select v-model="f.estado" class="s-input w-[150px]" @change="reload">
                        <option v-for="(n, k) in ESTADO" :key="k" :value="k">{{ n }}</option>
                    </select>
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2 py-3 text-[12px]">
                <span class="rounded-full border border-line bg-[#f5f6f7] px-2 py-[2px]">Saldo de cartera: {{ SALDO[f.saldo] }}</span>
                <span class="rounded-full border border-line bg-[#f5f6f7] px-2 py-[2px]">Estado de {{ party }}: {{ ESTADO[f.estado] }}</span>
                <button type="button" class="s-btn-link" @click="clear">Limpiar filtros</button>
            </div>
        </div>

        <table class="s-table">
            <thead>
                <tr>
                    <th>{{ cfg.party }}</th>
                    <th>Sucursal</th>
                    <th class="text-right">Saldo vencido</th>
                    <th class="text-right">Saldo por vencer</th>
                    <th class="text-right">Saldo a favor</th>
                    <th class="text-right">{{ cfg.total }}</th>
                    <th>Estado</th>
                    <th class="w-[170px]">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows.data" :key="r.id">
                    <td class="max-w-[240px]">
                        <Link :href="`/terceros/${r.id}/editar`" class="block truncate text-primary">{{ r.name }}</Link>
                        <div class="text-[12px]">{{ r.identification }}</div>
                    </td>
                    <td>{{ r.branch }}</td>
                    <td class="text-right">{{ money(r.overdue) }}</td>
                    <td class="text-right">{{ money(r.current) }}</td>
                    <td class="text-right">{{ money(r.credit) }}</td>
                    <td class="text-right">{{ money(r.total) }}</td>
                    <td>{{ r.active ? 'Activo' : 'Inactivo' }}</td>
                    <td>
                        <Link :href="`${cfg.url}/${r.id}`" class="s-btn s-btn-outline h-[28px] w-full text-[12px]">Ver estado de cuenta</Link>
                    </td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!rows.data.length" />
        <Pager :paginator="rows" />
    </section>
</template>
