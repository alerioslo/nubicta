<script setup>
import { onMounted, ref } from 'vue';
import QRCode from 'qrcode';

/* Representacion grafica de un documento de venta (impresion individual, por lotes y PDF del correo). */
const props = defineProps({ doc: Object, company: Object, resolution: Object });
const d = props.doc;
const qr = ref('');
const money = (v) => Number(v || 0).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (v) => Number(v || 0).toLocaleString('es-CO', { maximumFractionDigits: 4 });
const TITLES = { FV: 'Factura electrónica de venta', NC: 'Nota crédito electrónica', ND: 'Nota débito electrónica', CT: 'Cotización', RM: 'Remisión' };
const title = (d.dian ? TITLES[d.type] : TITLES[d.type]?.replace(' electrónica', '')) ?? d.type_name;

onMounted(async () => {
    if (d.dian?.qr) qr.value = await QRCode.toDataURL(d.dian.qr, { margin: 0, width: 140 });
});
</script>

<template>
    <article class="sheet">
        <header class="top">
            <div class="brand">
                <img v-if="company?.logo" :src="company.logo" alt="" class="logo" />
                <div>
                    <h1>{{ company?.trade_name || company?.name }}</h1>
                    <p v-if="company?.trade_name">{{ company.name }}</p>
                    <p>NIT {{ company?.nit }}</p>
                    <p>{{ company?.address }}<span v-if="company?.city"> - {{ company.city }}</span></p>
                    <p>{{ company?.phone }} {{ company?.email }}</p>
                </div>
            </div>
            <div class="docbox">
                <p class="t">{{ title }}</p>
                <p class="n">No. {{ d.name }}</p>
                <img v-if="qr" :src="qr" alt="QR DIAN" class="qr" />
            </div>
        </header>

        <section v-if="d.status === 'annulled'" class="annul">DOCUMENTO ANULADO</section>

        <section class="grid">
            <div><b>Señor(es):</b> {{ d.contact?.name }}</div>
            <div><b>Fecha de expedición:</b> {{ d.date }}</div>
            <div><b>NIT / C.C.:</b> {{ d.contact?.identification }}</div>
            <div><b>Fecha de vencimiento:</b> {{ d.due_date }}</div>
            <div><b>Dirección:</b> {{ d.contact?.address }}</div>
            <div><b>Teléfono:</b> {{ d.contact?.phone }}</div>
            <div><b>Email:</b> {{ d.contact?.email }}</div>
            <div v-if="d.seller"><b>Vendedor:</b> {{ d.seller }}</div>
            <div v-if="d.related"><b>Documento referencia:</b> {{ d.related }}</div>
        </section>

        <table class="items">
            <thead><tr><th>Ítem</th><th>Código</th><th>Descripción</th><th class="r">Cantidad</th><th class="r">Vr. unitario</th><th class="r">Descuento</th><th>Impuestos</th><th class="r">Valor total</th></tr></thead>
            <tbody>
                <tr v-for="(i, k) in d.items" :key="k">
                    <td>{{ k + 1 }}</td><td>{{ i.code }}</td><td>{{ i.description }}</td><td class="r">{{ qty(i.quantity) }}</td>
                    <td class="r">{{ money(i.price) }}</td><td class="r">{{ i.discount ? money(i.discount) : '' }}</td><td>{{ i.taxes }}</td><td class="r">{{ money(i.total) }}</td>
                </tr>
            </tbody>
        </table>

        <section class="bottom">
            <div class="left">
                <p v-if="d.payments?.length"><b>Formas de pago:</b> <span v-for="(p, k) in d.payments" :key="k">{{ p.name }} {{ money(p.value) }}{{ k < d.payments.length - 1 ? ', ' : '' }}</span></p>
                <p v-if="d.observations"><b>Observaciones:</b> {{ d.observations }}</p>
                <p v-if="resolution?.resolution_number" class="small">
                    Resolución DIAN No. {{ resolution.resolution_number }} del {{ resolution.resolution_date }}, prefijo {{ resolution.prefix }} del {{ resolution.range_from }} al {{ resolution.range_to }}<span v-if="resolution.valid_to">, vigente hasta {{ resolution.valid_to }}</span>.
                </p>
                <p v-if="d.dian?.cufe" class="small cufe"><b>CUFE:</b> {{ d.dian.cufe }}</p>
            </div>
            <table class="totals">
                <tr><td>Subtotal</td><td class="r">{{ money(d.totals.subtotal) }}</td></tr>
                <tr v-if="d.totals.discount"><td>Descuentos</td><td class="r">{{ money(d.totals.discount) }}</td></tr>
                <tr><td>Impuestos</td><td class="r">{{ money(d.totals.tax) }}</td></tr>
                <tr v-if="d.totals.withholding"><td>Retenciones</td><td class="r">-{{ money(d.totals.withholding) }}</td></tr>
                <tr class="tt"><td>Total</td><td class="r">{{ money(d.totals.total) }}</td></tr>
            </table>
        </section>
        <footer>Software propio de facturación electrónica · Representación gráfica del documento {{ d.name }}</footer>
    </article>
</template>

<style scoped>
.sheet { width: 816px; margin: 0 auto 30px; background: #fff; padding: 32px; font: 11px/1.4 Arial, sans-serif; color: #222; box-shadow: 0 0 6px #0002; }
.top { display: flex; justify-content: space-between; gap: 20px; border-bottom: 2px solid #00a6e0; padding-bottom: 12px; }
.brand { display: flex; gap: 12px; }
.logo { max-height: 70px; max-width: 160px; object-fit: contain; }
h1 { font-size: 16px; font-weight: bold; }
.docbox { text-align: center; min-width: 200px; border: 1px solid #ccc; padding: 8px; }
.docbox .t { font-weight: bold; font-size: 12px; }
.docbox .n { font-size: 14px; font-weight: bold; color: #00a6e0; }
.qr { width: 110px; height: 110px; margin: 6px auto 0; }
.annul { margin-top: 10px; text-align: center; color: #c62828; font-weight: bold; font-size: 16px; border: 2px solid #c62828; padding: 4px; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 20px; margin: 12px 0; padding: 8px; border: 1px solid #ddd; }
.items { width: 100%; border-collapse: collapse; }
.items th { background: #00a6e0; color: #fff; text-align: left; padding: 4px; }
.items td { border-bottom: 1px solid #eee; padding: 4px; vertical-align: top; }
.r { text-align: right; }
.bottom { display: flex; justify-content: space-between; gap: 20px; margin-top: 12px; }
.left { flex: 1; display: flex; flex-direction: column; gap: 6px; }
.small { font-size: 9.5px; color: #555; }
.cufe { word-break: break-all; }
.totals { width: 260px; border-collapse: collapse; }
.totals td { padding: 3px 4px; border-bottom: 1px solid #eee; }
.totals .tt td { font-weight: bold; font-size: 13px; border-top: 2px solid #00a6e0; }
footer { margin-top: 20px; text-align: center; font-size: 9px; color: #888; }
@media print { .sheet { box-shadow: none; margin: 0; width: auto; page-break-after: always; } }
</style>
