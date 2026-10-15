<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';
import ListFilters from '../../Components/ListFilters.vue';

const props = defineProps({
    receipts: { type: Object, required: true },
    type: { type: String, required: true },
    title: { type: String, required: true },
    tabs: { type: Array, required: true },
    active: { type: String, required: true },
    url: { type: String, required: true },
    filters: { type: Object, default: () => ({}) },
});
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' COP';
const rc = computed(() => props.type === 'RC');
const REPORTS = computed(() => rc.value
    ? [['Recibos de caja detallado por facturas', '/reportes/recibos-caja-facturas'], ['Movimiento de recibos de caja', '/reportes/movimiento-recibos?clase=RC'], ['Ver más reportes', '/reportes']]
    : [['Movimiento de recibos de pago / egreso', '/reportes/movimiento-recibos?clase=RP'], ['Ver más reportes', '/reportes']]);
const mail = (s) => (['sent', 'read'].includes(s) ? ['Enviado', 's-badge-ok'] : ['Sin enviar', 's-badge-warn']);
const sent = (r) => ['sent', 'read'].includes(r.mail_status);
const docUrl = (r) => `/documentos/recibos/${r.id}/imprimir`;

const menu = ref(null);
const sending = ref(null);
const to = ref('');
function openSend(r) {
    menu.value = null;
    sending.value = r;
    to.value = r.email || '';
}
function send() {
    router.post(`/documentos/recibos/${sending.value.id}/correo`, { to: to.value }, { preserveScroll: true, onSuccess: () => (sending.value = null) });
}
</script>

<template>
    <Head :title="active" />
    <PageHeader :title="title" :tabs="tabs" :active="active" />

    <section class="s-card mt-[18px]" @click="menu = null">
        <ListFilters :url="url" :filters="filters" :placeholder="rc ? 'Buscar por Identificación o Cliente' : 'Buscar por Identificación o Proveedor'">
            <div class="relative" @click.stop>
                <button type="button" class="s-btn s-btn-outline" @click="menu = menu === 'rep' ? null : 'rep'">Ver reportes <Icon icon="caret-down" /></button>
                <div v-if="menu === 'rep'" class="absolute right-0 top-[40px] z-30 w-[280px] rounded-[6px] bg-white py-[6px] shadow-lg">
                    <Link v-for="[l, u] in REPORTS" :key="l" :href="u" class="block px-[14px] py-[8px] text-[13px] text-ink hover:bg-[#f0f7fd]">{{ l }}</Link>
                </div>
            </div>
            <div class="relative inline-flex" @click.stop>
                <Link :href="rc ? '/ventas/recibos/crear' : '/compras/egresos/crear'" class="s-btn s-btn-primary rounded-r-none">{{ rc ? 'Crear Recibo de caja' : 'Crear Recibo de pago' }}</Link>
                <button type="button" class="s-btn s-btn-primary rounded-l-none border-l border-white/40 px-[12px]" aria-label="Más opciones" @click="menu = menu === 'crear' ? null : 'crear'"><Icon icon="caret-down" /></button>
                <div v-if="menu === 'crear'" class="absolute right-0 top-[40px] z-30 w-[230px] rounded-[6px] bg-white py-[6px] shadow-lg">
                    <Link href="/contabilidad/ajustes-cartera/crear" class="block px-[14px] py-[8px] text-[13px] text-ink hover:bg-[#f0f7fd]">Ajuste de cartera / proveedores</Link>
                </div>
            </div>
        </ListFilters>
        <table class="s-table">
            <thead>
                <tr>
                    <th>Fecha</th><th>Comprobante</th><th>{{ rc ? 'Cliente' : 'Proveedor' }}</th>
                    <th class="text-right">Total</th><th>Correo</th><th class="w-[150px]">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in receipts.data" :key="r.id" :class="{ 'opacity-60': r.annulled }">
                    <td class="whitespace-nowrap">{{ r.date }}</td>
                    <td><Link :href="`/documentos/recibos/${r.id}`" class="text-primary">{{ r.name }}</Link><div class="text-[11px] text-muted">{{ rc ? 'Recibo de caja' : 'Recibo de pago' }}<span v-if="r.annulled"> · Anulado</span></div></td>
                    <td class="max-w-[300px]"><div class="truncate text-primary">{{ r.contact }}</div><div class="text-[11px] text-muted">{{ r.identification }}</div></td>
                    <td class="whitespace-nowrap text-right">{{ money(r.total) }}</td>
                    <td><span class="s-badge" :class="mail(r.mail_status)[1]">{{ mail(r.mail_status)[0] }}</span></td>
                    <td>
                        <div v-if="!sent(r) && !r.annulled" class="relative flex" @click.stop>
                            <button type="button" class="s-btn s-btn-outline h-[28px] flex-1 rounded-r-none text-[12px]" @click="openSend(r)">Enviar por correo</button>
                            <button type="button" class="s-btn s-btn-outline h-[28px] rounded-l-none border-l-0 px-2" @click="menu = menu === r.id ? null : r.id"><Icon icon="caret-down" /></button>
                            <div v-if="menu === r.id" class="absolute right-0 top-[30px] z-20 w-[170px] rounded border border-line bg-white py-1 shadow-lg">
                                <a :href="docUrl(r)" target="_blank" rel="noopener" class="block px-3 py-2 text-[13px] hover:bg-[#f5f6f7]">Ver documento</a>
                            </div>
                        </div>
                        <a v-else :href="docUrl(r)" target="_blank" rel="noopener" class="s-btn s-btn-outline h-[28px] w-full text-[12px]">Ver documento</a>
                    </td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!receipts.data.length" />
        <Pager :paginator="receipts" />
    </section>

    <div v-if="sending" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40" @click.self="sending = null">
        <form class="s-card w-[420px] p-[20px]" @submit.prevent="send">
            <div class="text-[16px] font-bold text-ink">Enviar {{ sending.name }} por correo</div>
            <label class="mt-[12px] block text-[12px] text-muted">{{ rc ? 'Correo del cliente' : 'Correo del proveedor' }}
                <input v-model="to" type="email" required class="s-input mt-[4px] w-full" />
            </label>
            <div class="mt-[16px] flex justify-end gap-2">
                <button type="button" class="s-btn s-btn-cancel" @click="sending = null">Cancelar</button>
                <button class="s-btn s-btn-primary">Enviar</button>
            </div>
        </form>
    </div>
</template>
