<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../Components/PageHeader.vue';
import BarChart from '../Components/Charts/BarChart.vue';
import PieChart from '../Components/Charts/PieChart.vue';

const props = defineProps({
    periods: Object, selected: Object, updatedAt: String, ventas: Object, compras: Object, top: Object, vs: Object,
    cartera: Array, pagar: Array, gastos: Object, disponible: Array, tabs: Array,
});

const money = (v) => (v < 0 ? '-$ ' : '$ ') + Math.abs(v).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const sum = (a) => a.reduce((s, v) => s + v, 0);
const totalCompras = computed(() => sum(Object.values(props.compras.series).flat()));
const totalDisponible = computed(() => sum(props.disponible.map((d) => d.value)));
const setPeriod = (key, value) => router.get('/inicio/indicadores', { ...props.selected, [key]: value }, { preserveScroll: true, preserveState: true });
const refresh = () => router.reload();
</script>

<template>
    <Head title="Inicio" />
    <PageHeader title="Inicio" :tabs="tabs" active="Indicadores" />

    <div class="mt-[16px] flex items-center gap-3 text-[13px]">
        <span><b>Última actualización:</b> {{ updatedAt }}</span>
        <button type="button" class="s-btn s-btn-outline" @click="refresh">Actualizar indicadores</button>
    </div>

    <div class="mt-[16px] grid max-w-[1460px] grid-cols-2 gap-[18px]">
        <template v-for="card in [
            { key: 'ventas', title: 'Ventas' },
            { key: 'compras', title: 'Compras' },
            { key: 'top', title: 'Top 5: Productos más vendidos' },
            { key: 'vs', title: 'Ventas vs Compras' },
        ]" :key="card.key">
            <section class="s-card flex flex-col p-[20px]">
                <div class="flex items-center">
                    <h3 class="text-[20px] font-extrabold text-ink">{{ card.title }}</h3>
                    <button type="button" class="ml-auto text-primary" title="Actualizar" @click="refresh"><Icon icon="clock-rotate-left" /></button>
                </div>
                <label class="mt-3 flex w-[180px] flex-col text-[11px] font-bold text-ink">Periodo
                    <select class="s-input mt-1" :value="selected[card.key]" @change="setPeriod(card.key, $event.target.value)">
                        <option v-for="(l, k) in periods" :key="k" :value="k">{{ l }}</option>
                    </select>
                </label>
                <div class="mt-3 flex-1">
                    <BarChart v-if="card.key === 'ventas'" :labels="ventas.labels" :series="{ Ventas: ventas.values }" />
                    <BarChart v-else-if="card.key === 'compras'" :labels="compras.labels" :series="compras.series" />
                    <PieChart v-else-if="card.key === 'top'" :items="top.items" />
                    <BarChart v-else :labels="vs.labels" :series="vs.series" />
                </div>
                <div class="mt-3 flex items-end text-[13px] text-ink-soft">
                    <div>
                        <p v-if="card.key === 'compras'">Total compras: {{ money(totalCompras) }}</p>
                        <p v-if="card.key === 'top'">Total ventas: {{ money(top.total) }}</p>
                        <template v-if="card.key === 'vs'">
                            <p>Total ventas: {{ money(sum(vs.series.Ventas)) }}</p>
                            <p>Total Compras / Gastos / Doc. Soporte: {{ money(sum(Object.values(vs.series)[1])) }}</p>
                        </template>
                    </div>
                    <Link href="/reportes" class="ml-auto font-bold text-primary"><Icon icon="file-arrow-up" /> Ver reporte detallado</Link>
                </div>
            </section>
        </template>

        <section v-for="card in [{ title: 'Edades de cartera', rows: cartera }, { title: 'Cuentas por pagar', rows: pagar }]" :key="card.title" class="s-card flex flex-col p-[20px]">
            <h3 class="text-[20px] font-extrabold text-ink">{{ card.title }}</h3>
            <table class="mt-4 w-full border border-line text-[13px]">
                <tr v-for="(r, i) in card.rows" :key="r.label" :class="i % 2 ? 'bg-[#f5f7f9]' : ''">
                    <td class="border-r border-line px-3 py-[12px] font-bold text-ink">{{ r.label }}</td>
                    <td class="px-3 text-right">{{ money(r.value) }}</td>
                </tr>
            </table>
            <Link :href="card.title === 'Edades de cartera' ? '/ventas/clientes' : '/compras/proveedores'" class="ml-auto mt-4 font-bold text-primary"><Icon icon="file-arrow-up" /> Ver reporte detallado</Link>
        </section>

        <section class="s-card flex flex-col p-[20px]">
            <div class="flex items-center">
                <h3 class="text-[20px] font-extrabold text-ink">Gastos</h3>
                <button type="button" class="ml-auto text-primary" title="Actualizar" @click="refresh"><Icon icon="clock-rotate-left" /></button>
            </div>
            <label class="mt-3 flex w-[180px] flex-col text-[11px] font-bold text-ink">Periodo
                <select class="s-input mt-1" :value="selected.gastos" @change="setPeriod('gastos', $event.target.value)">
                    <option v-for="(l, k) in periods" :key="k" :value="k">{{ l }}</option>
                </select>
            </label>
            <PieChart class="mt-3 flex-1" :items="gastos.items" donut />
            <div class="mt-3 flex items-end text-[13px] text-ink-soft">
                <p>Total gastos: {{ money(gastos.total) }}</p>
                <Link href="/reportes" class="ml-auto font-bold text-primary"><Icon icon="file-arrow-up" /> Ver reporte detallado</Link>
            </div>
        </section>

        <section class="s-card flex flex-col p-[20px]">
            <h3 class="text-[20px] font-extrabold text-ink">Disponible</h3>
            <table class="mt-4 w-full border border-line text-[13px]">
                <tr v-for="(r, i) in disponible" :key="r.name" :class="i % 2 ? 'bg-[#f5f7f9]' : ''">
                    <td class="border-r border-line px-3 py-[12px] font-bold text-ink">{{ r.name }}</td>
                    <td class="px-3 text-right">{{ money(r.value) }}</td>
                </tr>
            </table>
            <div class="mt-auto flex items-end pt-4 text-[13px] text-ink-soft">
                <p>Total disponible: {{ money(totalDisponible) }}</p>
                <Link href="/bancos" class="ml-auto font-bold text-primary"><Icon icon="file-arrow-up" /> Ver reporte detallado</Link>
            </div>
        </section>
    </div>
</template>
