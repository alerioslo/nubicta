<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import EmptyState from '../../Components/EmptyState.vue';

const props = defineProps({
    side: { type: String, required: true },
    cfg: { type: Object, required: true },
    contact: { type: Object, required: true },
    docs: { type: Array, required: true },
    todos: { type: Boolean, default: false },
    tabs: { type: Array, required: true },
});

const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const totals = computed(() => ({
    total: props.docs.reduce((s, d) => s + d.total, 0),
    balance: props.docs.reduce((s, d) => s + d.balance, 0),
    overdue: props.docs.filter((d) => d.days > 0).reduce((s, d) => s + d.balance, 0),
}));
</script>

<template>
    <Head :title="`Estado de cuenta ${contact.name}`" />
    <PageHeader :title="`Estado de cuenta`" :back="cfg.url" />

    <section class="s-card mt-[18px] grid grid-cols-4 gap-4 p-[20px]">
        <div class="col-span-2">
            <div class="text-[16px] font-bold text-ink">{{ contact.name }}</div>
            <div class="text-muted">{{ contact.identification }}</div>
            <div class="text-[12px] text-muted">{{ contact.email }} {{ contact.phone }}</div>
        </div>
        <div>
            <div class="text-[12px] text-muted">Saldo vencido</div>
            <div class="text-[18px] font-bold text-danger">{{ money(totals.overdue) }}</div>
        </div>
        <div>
            <div class="text-[12px] text-muted">{{ side === 'clientes' ? 'Total cartera' : 'Total saldo' }}</div>
            <div class="text-[18px] font-bold text-ink">{{ money(totals.balance) }}</div>
        </div>
    </section>

    <section class="s-card mt-[18px]">
        <div class="flex items-center gap-3 p-[16px]">
            <Link :href="`${cfg.url}/${contact.id}`" class="s-subtab" :class="{ active: !todos }">Documentos con saldo</Link>
            <Link :href="`${cfg.url}/${contact.id}?todos=1`" class="s-subtab" :class="{ active: todos }">Todos los documentos</Link>
        </div>
        <table class="s-table">
            <thead>
                <tr>
                    <th>Comprobante</th>
                    <th v-if="side === 'proveedores'">Factura proveedor</th>
                    <th>Fecha elaboración</th>
                    <th>Fecha vencimiento</th>
                    <th class="text-right">Días vencidos</th>
                    <th class="text-right">Total</th>
                    <th class="text-right">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="d in docs" :key="d.id">
                    <td class="text-primary">{{ d.name }}</td>
                    <td v-if="side === 'proveedores'">{{ d.supplier_doc ?? '-' }}</td>
                    <td>{{ d.date }}</td>
                    <td>{{ d.due ?? '-' }}</td>
                    <td class="text-right" :class="{ 'text-danger': d.days > 0 }">{{ d.days || '-' }}</td>
                    <td class="text-right">{{ money(d.total) }}</td>
                    <td class="text-right">{{ money(d.balance) }}</td>
                </tr>
            </tbody>
            <tfoot v-if="docs.length">
                <tr class="font-bold">
                    <td :colspan="side === 'proveedores' ? 5 : 4" class="px-[14px] py-[10px] text-right">Totales</td>
                    <td class="px-[14px] text-right">{{ money(totals.total) }}</td>
                    <td class="px-[14px] text-right">{{ money(totals.balance) }}</td>
                </tr>
            </tfoot>
        </table>
        <EmptyState v-if="!docs.length" />
    </section>
</template>
