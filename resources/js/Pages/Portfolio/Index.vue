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

const COLUMNS = { branch: 'Sucursal', overdue: 'Saldo vencido', current: 'Saldo por vencer', credit: 'Saldo a favor', total: props.cfg.total, active: 'Estado' };
const storeKey = `portfolio-columns-${props.side}`;
const saved = typeof localStorage !== 'undefined' ? JSON.parse(localStorage.getItem(storeKey) || 'null') : null;
const visible = reactive(Object.fromEntries(Object.keys(COLUMNS).map((k) => [k, saved ? saved[k] !== false : true])));
const columnsOpen = ref(false);
const toggleColumn = (k) => {
    visible[k] = !visible[k];
    localStorage.setItem(storeKey, JSON.stringify(visible));
};
const menu = ref(null);
const adjustUrl = (r) => `/contabilidad/ajustes-cartera/crear?modulo=${props.side === 'clientes' ? 'ventas' : 'compras'}&tercero=${r.id}&nombre=${encodeURIComponent(`${r.identification} - ${r.name}`)}`;
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
                    <div class="relative">
                        <button type="button" class="s-btn s-btn-link" @click="columnsOpen = !columnsOpen"><Icon icon="table-columns" /> Columnas</button>
                        <div v-if="columnsOpen" class="absolute right-0 z-20 mt-1 w-[200px] rounded border border-line bg-white p-2 shadow-lg">
                            <label v-for="(n, k) in COLUMNS" :key="k" class="flex cursor-pointer items-center gap-2 px-2 py-1 text-[13px]">
                                <input type="checkbox" :checked="visible[k]" @change="toggleColumn(k)" /> {{ n }}
                            </label>
                        </div>
                    </div>
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
                    <th v-if="visible.branch">Sucursal</th>
                    <th v-if="visible.overdue" class="text-right">Saldo vencido</th>
                    <th v-if="visible.current" class="text-right">Saldo por vencer</th>
                    <th v-if="visible.credit" class="text-right">Saldo a favor</th>
                    <th v-if="visible.total" class="text-right">{{ cfg.total }}</th>
                    <th v-if="visible.active">Estado</th>
                    <th class="w-[200px]">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows.data" :key="r.id">
                    <td class="max-w-[240px]">
                        <Link :href="`${cfg.url}/${r.id}`" class="block truncate text-primary">{{ r.name }}</Link>
                        <div class="text-[12px]">{{ r.identification }}</div>
                    </td>
                    <td v-if="visible.branch">{{ r.branch }}</td>
                    <td v-if="visible.overdue" class="text-right">{{ money(r.overdue) }}</td>
                    <td v-if="visible.current" class="text-right">{{ money(r.current) }}</td>
                    <td v-if="visible.credit" class="text-right">{{ money(r.credit) }}</td>
                    <td v-if="visible.total" class="text-right">{{ money(r.total) }}</td>
                    <td v-if="visible.active">{{ r.active ? 'Activo' : 'Inactivo' }}</td>
                    <td>
                        <div class="relative flex">
                            <Link :href="`${cfg.url}/${r.id}`" class="s-btn s-btn-outline h-[28px] flex-1 rounded-r-none text-[12px]">Ver estado de cuenta</Link>
                            <button type="button" class="s-btn s-btn-outline h-[28px] rounded-l-none border-l-0 px-2" @click="menu = menu === r.id ? null : r.id"><Icon icon="caret-down" /></button>
                            <div v-if="menu === r.id" class="absolute right-0 top-[30px] z-20 w-[230px] rounded border border-line bg-white py-1 shadow-lg">
                                <Link :href="adjustUrl(r)" class="block px-3 py-2 text-[13px] hover:bg-[#f5f6f7]">Ajuste de cartera y proveedores</Link>
                                <Link :href="`/terceros/${r.id}/editar`" class="block px-3 py-2 text-[13px] hover:bg-[#f5f6f7]">Editar</Link>
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!rows.data.length" />
        <Pager :paginator="rows" />
    </section>
</template>
