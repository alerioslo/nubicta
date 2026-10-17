<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';

/* Saldos iniciales (CC-999) por tipo: cuentas por cobrar, por pagar e inventario. */
const props = defineProps({ kind: String, title: String, kinds: Object, rows: Object, totals: Object, q: String });
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const search = ref(props.q);

const find = () => router.get(`/configuracion/saldos-iniciales/${props.kind}`, search.value ? { q: search.value } : {}, { preserveState: true });
</script>

<template>
    <Head :title="title" />
    <PageHeader :title="title" back="/configuracion" />
    <nav class="s-subtabs mt-[12px]">
        <Link v-for="(t, k) in kinds" :key="k" :href="`/configuracion/saldos-iniciales/${k}`" class="s-subtab" :class="{ active: k === kind }">{{ t.replace('Saldos iniciales ', '') }}</Link>
    </nav>

    <section class="s-card mt-[14px] flex items-end justify-between gap-[16px] px-[20px] py-[16px] text-[13px]">
        <label class="block w-[380px]"><span class="s-label">Buscar tercero</span><input v-model="search" class="s-input w-full" placeholder="Nombre o identificación" @keyup.enter="find" /></label>
        <div class="flex gap-[10px]">
            <a :href="`/contabilidad/saldos-iniciales/${kind}/plantilla`" class="s-btn s-btn-outline"><Icon icon="download" /> Descargar plantilla</a>
            <Link :href="`/contabilidad/saldos-iniciales/${kind}`" class="s-btn s-btn-primary"><Icon icon="plus" /> Ingresar saldos iniciales</Link>
        </div>
    </section>

    <section class="s-card mt-[14px] overflow-hidden">
        <p class="border-b border-[#edf0f2] px-[20px] py-[10px] text-[12px] text-muted">Saldos registrados con los comprobantes <b>CC-999 Saldos iniciales</b> traídos de Siigo: {{ totals.n }} movimientos.</p>
        <div class="s-table-wrap">
            <table class="s-table text-[12.5px]">
                <thead><tr><th>Fecha</th><th>Comprobante</th><th>Cuenta</th><th>Identificación</th><th>Tercero</th><th class="text-right">Débito</th><th class="text-right">Crédito</th></tr></thead>
                <tbody>
                    <tr v-for="(r, i) in rows.data" :key="i">
                        <td>{{ r.date.split('-').reverse().join('/') }}</td><td>{{ r.document }}</td><td>{{ r.account_code }} - {{ r.account_name }}</td>
                        <td>{{ r.identification }}</td><td>{{ r.contact_name }}</td><td class="text-right">{{ money(r.debit) }}</td><td class="text-right">{{ money(r.credit) }}</td>
                    </tr>
                    <tr v-if="!rows.data.length"><td colspan="7" class="py-[30px] text-center text-muted">No hay saldos iniciales registrados</td></tr>
                </tbody>
                <tfoot v-if="rows.data.length"><tr class="bg-th font-bold"><td colspan="5" class="px-[14px] py-[10px]">Total</td><td class="px-[14px] text-right">{{ money(totals.debit) }}</td><td class="px-[14px] text-right">{{ money(totals.credit) }}</td></tr></tfoot>
            </table>
        </div>
        <Pager :paginator="rows" />
    </section>
</template>
