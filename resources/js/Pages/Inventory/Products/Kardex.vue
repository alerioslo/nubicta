<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';
import Pager from '../../../Components/Pager.vue';
import EmptyState from '../../../Components/EmptyState.vue';

const props = defineProps({
    product: { type: Object, required: true },
    stocks: { type: Array, required: true },
    movements: { type: Object, required: true },
    warehouses: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
});

const f = reactive({ bodega: '', desde: '', hasta: '', ...props.filters });
function reload() {
    router.get(`/productos/${props.product.id}/kardex`, Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
}
const n = (v, d = 2) => (v === null || v === undefined ? '' : Number(v).toLocaleString('es-CO', { maximumFractionDigits: d }));
</script>

<template>
    <Head :title="`Kardex ${product.code}`" />
    <PageHeader :title="`Kardex · ${product.code} ${product.name}`" :back="`/productos/${product.id}/editar`" />

    <div class="mt-[18px] grid grid-cols-4 gap-[14px]">
        <div class="s-card p-4"><p class="text-muted">Saldo total</p><p class="text-[20px] font-bold text-ink">{{ n(product.stock) }} {{ product.unit_label }}</p></div>
        <div class="s-card p-4"><p class="text-muted">Costo promedio</p><p class="text-[20px] font-bold text-ink">$ {{ n(product.average_cost) }}</p></div>
        <div class="s-card col-span-2 p-4">
            <p class="text-muted">Saldo por bodega</p>
            <p v-for="s in stocks" :key="s.warehouse" class="text-ink">{{ s.warehouse }}: <b>{{ n(s.quantity) }}</b></p>
        </div>
    </div>

    <section class="s-card mt-[18px]">
        <div class="flex items-end gap-3 p-4">
            <label><span class="s-label">Bodega</span>
                <select v-model="f.bodega" class="s-input w-[180px]" @change="reload">
                    <option value="">Todas</option>
                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
                </select>
            </label>
            <label><span class="s-label">Desde</span><input v-model="f.desde" type="date" class="s-input w-[150px]" @change="reload" /></label>
            <label><span class="s-label">Hasta</span><input v-model="f.hasta" type="date" class="s-input w-[150px]" @change="reload" /></label>
        </div>
        <table class="s-table">
            <thead>
                <tr>
                    <th>Fecha</th><th>Documento</th><th>Concepto</th><th>Bodega</th>
                    <th class="text-right">Entradas</th><th class="text-right">Salidas</th><th class="text-right">Costo unitario</th>
                    <th class="text-right">Saldo</th><th class="text-right">Saldo en pesos</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="m in movements.data" :key="m.id">
                    <td>{{ m.date }}</td><td>{{ m.document }}</td><td>{{ m.concept }}</td><td>{{ m.warehouse }}</td>
                    <td class="text-right text-ok">{{ n(m.in) }}</td><td class="text-right text-danger">{{ n(m.out) }}</td>
                    <td class="text-right">{{ n(m.unit_cost) }}</td><td class="text-right font-semibold">{{ n(m.balance_quantity) }}</td>
                    <td class="text-right">{{ n(m.balance_cost) }}</td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!movements.data.length" />
        <Pager :paginator="movements" />
    </section>
</template>
