<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import EmptyState from './EmptyState.vue';

const props = defineProps({
    side: { type: String, required: true },
    url: { type: String, required: true },
    contact: { type: Object, required: true },
    docs: { type: Array, required: true },
    todos: { type: Boolean, default: false },
    baseUrl: { type: String, required: true },
    showContact: { type: Boolean, default: true },
});

const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const sum = (k) => props.docs.reduce((s, d) => s + d[k], 0);
const totals = computed(() => ({ debt: sum('debt'), favor: sum('favor'), overdue: sum('overdue'), upcoming: sum('upcoming') }));
const customers = props.side === 'clientes';
const tiles = computed(() => [
    { label: customers ? 'Deuda por cobrar' : 'Deuda por pagar', value: totals.value.debt, cls: 'bg-[#13a3a3]' },
    { label: 'Valor a favor', value: totals.value.favor, cls: 'bg-[#6cc644]' },
    { label: customers ? 'Saldo cartera' : 'Saldo proveedor', value: totals.value.debt + totals.value.favor, cls: 'bg-[#5aa82e]' },
    { label: 'Vencido', value: totals.value.overdue, cls: 'bg-[#e74c3c]' },
    { label: 'Por vencer', value: totals.value.upcoming, cls: 'bg-[#f39c12]' },
]);
const adjustUrl = `/contabilidad/ajustes-cartera/crear?modulo=${customers ? 'ventas' : 'compras'}&tercero=${props.contact.id}&nombre=${encodeURIComponent(`${props.contact.identification} - ${props.contact.name}`)}`;
const sep = props.baseUrl.includes('?') ? '&' : '?';

const sending = ref(false);
const mail = useForm({ email: props.contact.email || '' });
const send = () => mail.post(`${props.url}/${props.contact.id}/enviar`, { preserveScroll: true, onSuccess: () => (sending.value = false) });
</script>

<template>
    <section class="s-card mt-[18px] p-[20px]">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div v-if="showContact">
                <div class="text-[16px] font-bold text-ink">{{ contact.name }}</div>
                <div class="text-muted">{{ contact.identification }}</div>
                <div class="text-[12px] text-muted">{{ contact.email }} {{ contact.phone }}</div>
            </div>
            <div class="ml-auto flex gap-2">
                <Link :href="adjustUrl" class="s-btn s-btn-outline">Ajuste de cartera</Link>
                <button v-if="customers" type="button" class="s-btn s-btn-primary" @click="sending = !sending">Enviar estado de cuenta</button>
            </div>
        </div>
        <form v-if="sending" class="mt-[14px] flex items-end gap-2" @submit.prevent="send">
            <label class="flex-1 text-[12px] text-muted">Correo del cliente
                <input v-model="mail.email" type="email" required class="s-input mt-[4px] w-full" />
            </label>
            <button class="s-btn s-btn-primary" :disabled="mail.processing">Enviar</button>
        </form>
        <p v-if="mail.errors.email" class="s-error">{{ mail.errors.email }}</p>
        <div class="mt-[8px] text-center text-[12px] text-muted">Moneda: Peso colombiano</div>
        <div class="mt-[6px] grid grid-cols-5 gap-[4px]">
            <div v-for="t in tiles" :key="t.label" class="px-[10px] py-[8px] text-white" :class="t.cls">
                <div class="text-[20px] font-bold">{{ money(t.value) }} <span class="text-[14px]">COP</span></div>
                <div class="text-[12px]">{{ t.label }}</div>
            </div>
        </div>
    </section>

    <section class="s-card mt-[18px]">
        <div class="flex items-center gap-3 p-[16px]">
            <Link :href="baseUrl" class="s-subtab" :class="{ active: !todos }" preserve-scroll>Documentos con saldo</Link>
            <Link :href="`${baseUrl}${sep}todos=1`" class="s-subtab" :class="{ active: todos }" preserve-scroll>Todos los documentos</Link>
            <span class="ml-auto text-[12px] text-muted">Encontrados ({{ docs.length }})</span>
        </div>
        <div class="s-table-wrap">
            <table class="s-table">
                <thead>
                    <tr>
                        <th>Detalle vencimiento</th>
                        <th v-if="side === 'proveedores'">Factura proveedor</th>
                        <th>Fecha vencimiento</th>
                        <th class="text-right">Cuota</th>
                        <th class="text-right">{{ customers ? 'Deuda por cobrar' : 'Deuda por pagar' }}</th>
                        <th class="text-right">Valor a favor</th>
                        <th class="text-right">Valor vencido</th>
                        <th class="text-right">Valor por vencer</th>
                        <th class="text-right">Días mora/Por vencer</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in docs" :key="d.id">
                        <td class="text-primary">{{ d.name }}</td>
                        <td v-if="side === 'proveedores'">{{ d.supplier_doc ?? '-' }}</td>
                        <td>{{ d.due ?? d.date }}</td>
                        <td class="text-right">{{ d.quote }}</td>
                        <td class="text-right">{{ money(d.debt) }}</td>
                        <td class="text-right">{{ money(d.favor) }}</td>
                        <td class="text-right" :class="{ 'text-danger': d.overdue > 0 }">{{ money(d.overdue) }}</td>
                        <td class="text-right">{{ money(d.upcoming) }}</td>
                        <td class="text-right" :class="{ 'text-danger': d.days > 0 }">{{ d.days ?? '-' }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="docs.length">
                    <tr class="font-bold">
                        <td :colspan="side === 'proveedores' ? 4 : 3" class="px-[14px] py-[10px] text-right">Totales</td>
                        <td class="px-[14px] text-right">{{ money(totals.debt) }}</td>
                        <td class="px-[14px] text-right">{{ money(totals.favor) }}</td>
                        <td class="px-[14px] text-right">{{ money(totals.overdue) }}</td>
                        <td class="px-[14px] text-right">{{ money(totals.upcoming) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <EmptyState v-if="!docs.length" />
    </section>
</template>
