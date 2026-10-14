<script setup>
import { ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { onClickOutside } from '../../composables/clickOutside';
import PageHeader from '../../Components/PageHeader.vue';
import EmptyState from '../../Components/EmptyState.vue';

defineProps({
    rows: { type: Array, required: true },
    total: { type: Number, default: 0 },
    as_of: { type: String, default: null },
    tabs: { type: Array, required: true },
    year_start: { type: String, default: '' },
    today: { type: String, default: '' },
});
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const COLS = { code: 'Código cuenta contable', name: 'Cuenta bancaria / caja' };
const show = ref({ code: true, name: true, ...JSON.parse(localStorage.getItem('banks.columns') ?? '{}') });
watch(show, (v) => localStorage.setItem('banks.columns', JSON.stringify(v)), { deep: true });
const open = ref(false);
const menu = ref(null);
onClickOutside(menu, () => (open.value = false));
</script>

<template>
    <Head title="Cajas y bancos" />
    <PageHeader title="Cajas y bancos" :tabs="tabs" active="Cajas y bancos" />
    <h2 class="-mx-[22px] border-b border-line bg-white px-[22px] py-[18px] text-[22px] font-semibold text-ink">Saldos de cuentas bancarias / cajas</h2>

    <section class="s-card mt-[18px]">
        <div class="flex items-center justify-end gap-3 px-[16px] py-[14px]">
            <div ref="menu" class="relative">
                <button type="button" class="s-btn-link" @click="open = !open"><Icon icon="sliders" /> Columnas</button>
                <div v-if="open" class="s-card absolute right-0 top-[30px] z-30 w-[240px] p-3 shadow-lg">
                    <label v-for="(label, k) in COLS" :key="k" class="flex items-center gap-2 py-1 text-[13px]"><input v-model="show[k]" type="checkbox" /> {{ label }}</label>
                    <label class="flex items-center gap-2 py-1 text-[13px] text-muted"><input type="checkbox" checked disabled /> Saldo</label>
                </div>
            </div>
            <a href="/bancos/excel" class="s-btn s-btn-outline"><Icon icon="download" /> Descargar</a>
        </div>
        <table class="s-table">
            <thead>
                <tr>
                    <th v-if="show.code">Código cuenta contable</th>
                    <th v-if="show.name">Cuenta bancaria / caja</th>
                    <th class="text-right">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.id">
                    <td v-if="show.code">{{ r.code }}</td>
                    <td v-if="show.name"><a href="/contabilidad/cuentas" target="_blank" class="text-primary hover:underline">{{ r.name }}</a></td>
                    <td class="text-right"><a :href="`/contabilidad/libro-auxiliar?cuenta_desde=${r.code}&cuenta_hasta=${r.code}&desde=${year_start}&hasta=${today}`" target="_blank" class="text-primary hover:underline">{{ money(r.balance) }}</a></td>
                </tr>
            </tbody>
            <tfoot v-if="rows.length">
                <tr class="font-bold">
                    <td class="px-[14px] py-[10px]" :colspan="(show.code ? 1 : 0) + (show.name ? 1 : 0) || 1">Total general</td>
                    <td class="px-[14px] text-right">{{ money(total) }}</td>
                </tr>
            </tfoot>
        </table>
        <EmptyState v-if="!rows.length" />
    </section>
</template>
