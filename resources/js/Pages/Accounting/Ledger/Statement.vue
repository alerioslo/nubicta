<script setup>
import { Head } from '@inertiajs/vue3';
import LedgerFilters from '../../../Components/LedgerFilters.vue';
import EmptyState from '../../../Components/EmptyState.vue';

const props = defineProps({ title: String, tabs: Array, filters: Object, types: Array, kind: String, rows: Array, summary: Array });
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
// Pasivo, patrimonio e ingresos son de naturaleza credito: se muestran en positivo.
const shown = (r) => (props.kind === 'resultados' ? (['4'].includes(r.code[0]) ? -r.period : r.period) : (['2', '3'].includes(r.code[0]) ? -r.balance : r.balance));
const url = props.kind === 'resultados' ? '/contabilidad/estado-resultados' : '/contabilidad/situacion-financiera';
</script>

<template>
    <Head :title="title" />
    <h1 class="pt-[10px] text-[24px] text-primary">{{ title }}</h1>
    <LedgerFilters :url="url" :filters="filters" :fields="kind === 'resultados' ? ['fechas'] : ['corte']" />

    <div class="mt-[14px] grid grid-cols-[1fr_340px] items-start gap-[14px]">
        <section class="s-card">
            <div class="s-table-wrap">
                <table class="s-table text-[12px]">
                    <thead><tr><th>Código</th><th>Cuenta</th><th class="text-right">Valor</th></tr></thead>
                    <tbody>
                        <tr v-for="r in rows" :key="r.code" :class="{ 'font-bold': r.level === 1, 'font-semibold': r.level === 2 }">
                            <td :style="{ paddingLeft: `${8 + (r.level - 1) * 14}px` }">{{ r.code }}</td><td>{{ r.name }}</td><td class="text-right">{{ money(shown(r)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="!rows.length" />
        </section>
        <section class="s-card p-[18px] text-[13px]">
            <div v-for="s in summary" :key="s.label" class="flex justify-between border-b border-[#edf0f2] py-[8px] last:border-0 last:font-bold">
                <span>{{ s.label }}</span><span>{{ money(s.value) }}</span>
            </div>
        </section>
    </div>
</template>
