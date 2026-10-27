<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDialog } from '../../../composables/dialog';

const props = defineProps({
    product: { type: Object, required: true },
    costYear: { type: Number, required: true },
    costYears: { type: Array, required: true },
    costs: { type: Array, required: true },
    showCosts: { type: Boolean, default: false },
    canSeeCosts: { type: Boolean, default: false },
});

const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const money = (v) => Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const base = `/productos/${props.product.id}`;
const drawer = ref(props.showCosts);
const year = ref(props.costYear);
const v = (x) => (x === null || x === undefined || x === '' ? '-' : x);
const yes = (b) => (b ? 'Si' : 'No');

const changeYear = () => router.get(base, { anio: year.value }, { preserveState: true, preserveScroll: true, only: ['costs', 'costYear'] });
const toggle = () => router.post(`${base}/estado`, {}, { preserveScroll: true });
const remove = async () => {
    if (await confirmDialog(`¿Eliminar el producto ${props.product.name}?`)) router.post(`${base}/eliminar`);
};
</script>

<template>
    <Head :title="`Producto - ${product.name}`" />
    <div class="-mx-3 -mt-[18px] flex h-[52px] md:-mx-[18px] items-center justify-between bg-primary px-[18px] text-white">
        <h1 class="text-[17px] font-semibold">Producto - {{ product.name }}</h1>
        <Link href="/productos" class="text-[18px]" aria-label="Cerrar"><Icon icon="xmark" /></Link>
    </div>

    <div class="mt-[16px] flex flex-wrap items-start justify-between gap-3">
        <div class="text-[13px] text-ink">
            <div>Creado: {{ product.created }}</div>
            <div>Modificado: {{ product.updated }}</div>
        </div>
        <div class="flex flex-wrap gap-[6px]">
            <button type="button" class="s-btn border border-[#e5484d] bg-white text-[12px] text-[#e5484d]" @click="remove"><Icon icon="trash-can" /> Eliminar</button>
            <Link :href="`/productos/crear?copiar=${product.id}`" class="s-btn s-btn-outline text-[12px]"><Icon icon="copy" /> Duplicar</Link>
            <Link :href="`${base}/editar`" class="s-btn s-btn-outline text-[12px]"><Icon icon="pen" /> Editar</Link>
            <button v-if="canSeeCosts" type="button" class="s-btn s-btn-outline text-[12px]" @click="drawer = true">Costo promedio</button>
        </div>
    </div>

    <div class="mt-[16px] grid grid-cols-[1fr_280px] items-start gap-[18px]">
        <div class="space-y-[18px]">
            <section class="s-card p-[18px]">
                <div class="text-[13px] font-semibold text-ink">{{ product.name }}</div>
                <div v-if="product.stock !== null" class="mt-[4px] text-[12.5px] text-muted">
                    Stock actual: {{ money(product.stock) }} {{ product.unit === 'unidad' ? 'und.' : product.unit }}
                    <Link :href="`${base}/kardex`" class="text-primary">Ver stock</Link>
                </div>
                <label class="mt-[10px] flex items-center gap-[10px] text-[13px] text-ink">Activo
                    <span class="s-switch"><input type="checkbox" :checked="product.active" @change="toggle" /><span></span></span>
                </label>
            </section>

            <section class="s-card">
                <h2 class="border-b border-[#edf0f2] px-[18px] py-[16px] text-[15px] font-semibold text-ink">Datos generales</h2>
                <div class="grid grid-cols-1 gap-x-[20px] gap-y-[18px] p-[18px] text-[12.5px] sm:grid-cols-2 lg:grid-cols-3">
                    <div><div class="font-semibold text-ink">Tipo</div><div class="text-muted">{{ product.type }}</div></div>
                    <div><div class="font-semibold text-ink">Nombre del producto</div><div class="text-muted">{{ product.name }}</div></div>
                    <div><div class="font-semibold text-ink">Producto inventariable</div><div class="text-muted">{{ yes(product.inventoriable) }}</div></div>
                    <div><div class="font-semibold text-ink">Categoría del producto</div><div class="text-muted">{{ v(product.group) }}</div></div>
                    <div><div class="font-semibold text-ink">Código de barras</div><div class="text-muted">{{ v(product.barcode) }}</div></div>
                    <div><div class="font-semibold text-ink">Visible en facturas de venta</div><div class="text-muted">{{ yes(product.visible) }}</div></div>
                    <div><div class="font-semibold text-ink">Código de producto (SKU)</div><div class="text-muted">{{ product.code }}</div></div>
                    <div><div class="font-semibold text-ink">Unidad de medida DIAN</div><div class="text-muted">{{ v(product.dian_unit) }}</div></div>
                </div>
            </section>

            <section class="s-card">
                <h2 class="border-b border-[#edf0f2] px-[18px] py-[16px] text-[15px] font-semibold text-ink">Descripción y stock</h2>
                <div class="grid grid-cols-1 gap-x-[20px] gap-y-[18px] p-[18px] text-[12.5px] sm:grid-cols-2 lg:grid-cols-3">
                    <div><div class="font-semibold text-ink">Referencia</div><div class="text-muted">{{ v(product.reference) }}</div></div>
                    <div><div class="truncate font-semibold text-ink">Unidad de medida de la factura</div><div class="text-muted">{{ v(product.unit) }}</div></div>
                    <div><div class="font-semibold text-ink">Stock mínimo</div><div class="text-muted">{{ product.min_stock }}</div></div>
                    <div class="col-span-3"><div class="font-semibold text-ink">Descripción larga</div><div class="whitespace-pre-line text-muted">{{ v(product.description) }}</div></div>
                </div>
            </section>
        </div>

        <div class="space-y-[18px]">
            <section class="s-card">
                <div class="flex items-center justify-between border-b border-[#edf0f2] px-[16px] py-[16px]">
                    <h2 class="text-[15px] font-semibold text-ink">Lista de precios</h2>
                    <Link :href="`${base}/editar`" class="text-[11px] text-primary">Ver todos</Link>
                </div>
                <div class="space-y-[10px] p-[16px] text-[12.5px]">
                    <div v-for="p in product.prices" :key="p.name" class="flex justify-between"><span class="font-semibold text-ink">{{ p.name }}</span><span class="text-muted">{{ money(p.value) }} {{ p.currency }}</span></div>
                    <div v-if="!product.prices.length" class="text-muted">-</div>
                </div>
            </section>
            <section class="s-card">
                <h2 class="border-b border-[#edf0f2] px-[16px] py-[16px] text-[15px] font-semibold text-ink">Impuestos</h2>
                <div class="space-y-[10px] p-[16px] text-[12.5px]">
                    <div class="flex justify-between"><span class="font-semibold text-ink">Impuesto cargo</span><span class="text-muted">{{ v(product.charge) }}</span></div>
                    <div class="flex justify-between"><span class="font-semibold text-ink">Retención</span><span class="text-muted">{{ v(product.withholding) }}</span></div>
                </div>
            </section>
            <section class="s-card">
                <h2 class="border-b border-[#edf0f2] px-[16px] py-[16px] text-[15px] font-semibold text-ink">Datos exportación</h2>
                <div class="space-y-[10px] p-[16px] text-[12.5px]">
                    <div class="flex justify-between"><span class="font-semibold text-ink">Marca</span><span class="text-muted">{{ v(product.brand) }}</span></div>
                    <div class="flex justify-between"><span class="font-semibold text-ink">Modelo</span><span class="text-muted">{{ v(product.model) }}</span></div>
                    <div class="flex justify-between"><span class="font-semibold text-ink">Código arancelario</span><span class="text-muted">{{ v(product.tariff) }}</span></div>
                </div>
            </section>
        </div>
    </div>

    <div v-if="drawer" class="fixed inset-0 z-50 bg-black/40" @click.self="drawer = false">
        <aside class="absolute inset-y-0 right-0 flex w-[420px] max-w-full flex-col bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-[#edf0f2] px-[24px] py-[18px]">
                <h2 class="text-[16px] font-semibold text-ink">Costo promedio mensual</h2>
                <button type="button" class="text-[16px] text-ink" aria-label="Cerrar" @click="drawer = false"><Icon icon="xmark" /></button>
            </div>
            <div class="flex-1 overflow-y-auto px-[24px] py-[16px]">
                <label class="s-label">Año</label>
                <select v-model="year" class="s-input w-full" @change="changeYear"><option v-for="y in costYears" :key="y" :value="y">{{ y }}</option></select>
                <div class="s-table-wrap">
                    <table class="s-table mt-[24px] border border-[#edf0f2]">
                        <thead><tr><th>Mes</th><th class="text-right">Costo unitario promedio</th></tr></thead>
                        <tbody>
                            <tr v-for="(c, i) in costs" :key="i">
                                <td>{{ MONTHS[i] }}</td>
                                <td class="text-right" :class="c === null ? 'text-ink' : 'text-primary'">{{ c === null ? '-' : `$${money(c)}` }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="flex justify-end border-t border-[#edf0f2] px-[18px] py-[12px]">
                <button type="button" class="s-btn s-btn-primary" @click="drawer = false">Cerrar</button>
            </div>
        </aside>
    </div>
</template>
