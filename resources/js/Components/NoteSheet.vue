<script setup>
import { onMounted, ref } from 'vue';
import QRCode from 'qrcode';

/* Formato clasico para notas credito: recuadros con tercero, fechas, items y totales. */
const props = defineProps({ doc: Object, company: Object, software: String });
const d = props.doc;
const c = props.company ?? {};
const qr = ref('');
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const dots = (v) => String(v ?? '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
const nit = (id, dv) => (id ? dots(id) + (dv !== null && dv !== undefined && dv !== '' ? `-${dv}` : '') : '');
const TITLES = { NC: ['Nota Crédito', 'Fecha Nota Crédito'], ND: ['Nota Débito', 'Fecha Nota Débito'] };
const p = d.principal;
const head = p ? {
    logo: p.logo_url || c.logo, name: p.nombre, nit: p.identificacion, dv: p.dv, address: p.direccion, city: p.ciudad, phone: p.telefono,
} : { logo: c.logo, name: c.trade_name || c.name, nit: c.nit_number, dv: c.dv, address: c.address, city: c.city, phone: c.phone };
const roleLabel = (i) => (i.role === 'mandante' ? 'Mandante' : (i.role === 'mandatario' ? 'Mandatario' : ''));
const [title, dateLabel] = TITLES[d.type] ?? [d.type_name, 'Fecha'];
const due = (d.due_date ?? d.date ?? '').split('-').reverse().join('/');

onMounted(async () => {
    if (d.dian?.qr) qr.value = await QRCode.toDataURL(d.dian.qr, { margin: 0, width: 140 });
});
</script>

<template>
    <article class="sheet">
        <p v-if="d.dian" class="side">{{ software }}</p>
        <header class="top">
            <img v-if="head.logo" :src="head.logo" alt="" class="logo" />
            <div class="company">
                <b>{{ head.name }}</b>
                <p>NIT {{ nit(head.nit, head.dv) }}</p>
                <p>{{ head.address }}</p>
                <p v-if="head.phone">Tel: ({{ head.phone }})</p>
                <p v-if="head.city">{{ head.city }} - Colombia</p>
            </div>
            <img v-if="qr" :src="qr" alt="QR DIAN" class="qr" />
            <div class="numbox">
                <p>{{ title }}</p>
                <p class="b">No. {{ d.type_code }}&nbsp; {{ d.name.split('-').pop() }}</p>
            </div>
        </header>
        <p v-if="p" class="mandate">Mandatario: {{ c.trade_name || c.name }} · NIT {{ nit(c.nit_number, c.dv) }}</p>

        <section v-if="d.status === 'annulled'" class="annul">DOCUMENTO ANULADO</section>

        <div class="mid">
            <table class="box party">
                <tr><th>Señores</th><td colspan="3">{{ d.contact?.name }}</td></tr>
                <tr><th>NIT</th><td>{{ nit(d.contact?.identification, d.contact?.dv) }}</td><th>Teléfono</th><td>{{ d.contact_extra?.phone }}</td></tr>
                <tr><th>Dirección</th><td>{{ d.contact?.address }}</td><th>Ciudad</th><td>{{ d.contact_extra?.city }}</td></tr>
            </table>
            <table class="box dates">
                <tr><th>{{ dateLabel }}</th><td>{{ d.date }}</td></tr>
                <tr v-if="d.related_doc"><th>Factura de Venta</th><td>{{ d.related_doc.label }}</td></tr>
                <tr><th>Generación</th><td>{{ d.issued_at }}</td></tr>
                <tr><th>Expedición</th><td>{{ d.issued_at }}</td></tr>
                <tr><th>Vencimiento</th><td>{{ due }}</td></tr>
            </table>
        </div>

        <table class="box items">
            <thead><tr><th class="w-i">Ítem</th><th>Descripción</th><th class="w-q">Cantidad</th><th class="w-t">Vr. Total</th></tr></thead>
            <tbody>
                <tr v-for="(i, k) in d.items" :key="k"><td class="c">{{ k + 1 }}</td><td>{{ i.description }}<div v-if="roleLabel(i)" class="role">{{ roleLabel(i) }}</div></td><td class="r">{{ money(i.quantity) }}</td><td class="r">{{ money(i.total) }}</td></tr>
            </tbody>
        </table>
        <p class="count"><b>Total items:</b> {{ d.items.length }}</p>

        <div class="foot">
            <div class="left">
                <p><b>Valor en Letras:</b></p>
                <p class="in">{{ d.words }}</p>
                <p><b>Condiciones de Pago:</b></p>
                <p v-for="(p, k) in d.payments" :key="k" class="in pay"><span>{{ p.name }}</span><span>$</span><span>{{ money(p.value) }}</span></p>
            </div>
            <table class="box totals">
                <tr><th>Total Bruto</th><td>{{ money(d.totals.subtotal) }}</td></tr>
                <tr v-if="d.totals.discount"><th>Descuentos</th><td>{{ money(d.totals.discount) }}</td></tr>
                <tr v-for="t in d.tax_lines" :key="t.name"><th class="n">{{ t.name }}</th><td>{{ money(t.value) }}</td></tr>
                <tr v-for="t in d.retentions" :key="'r' + t.name"><th class="n">{{ t.name }}</th><td>-{{ money(t.value) }}</td></tr>
                <tr class="tt"><th>Total</th><td>{{ money(d.totals.total) }}</td></tr>
            </table>
        </div>

        <p v-if="p?.atencion" class="obs"><b>Atención:</b> {{ p.atencion }}</p>
        <p v-for="(cta, k) in (p?.cuentas || [])" :key="k" class="obs">{{ [cta.banco, cta.tipo, cta.numero].filter(Boolean).join(' · ') }}</p>
        <p class="obs"><b>Observaciones:</b> {{ d.observations }}</p>
        <div class="codes">
            <p v-if="d.dian?.cufe"><b>CUDE:</b> {{ d.dian.cufe }}</p>
            <p v-if="d.related_doc?.cufe"><b>CUFE factura:</b> {{ d.related_doc.cufe }}</p>
        </div>
    </article>
</template>

<style scoped>
.sheet { position: relative; width: 980px; margin: 0 auto 30px; background: #fff; padding: 22px 26px 26px 10px; font: 11px/1.4 Arial, Helvetica, sans-serif; color: #222; }
.mandate { text-align: center; font-weight: bold; margin: 6px 0; }
.role { font-size: 10px; color: #555; }
.side { position: absolute; right: 2px; top: 40px; writing-mode: vertical-rl; transform: rotate(180deg); font-size: 8px; color: #333; white-space: nowrap; }
.top { display: flex; align-items: flex-start; gap: 18px; padding-left: 90px; min-height: 120px; }
.logo { max-height: 100px; max-width: 210px; object-fit: contain; margin-top: 18px; }
.company { text-align: center; padding-top: 22px; }
.company b { font-size: 11px; }
.company p { margin: 2px 0; }
.qr { width: 136px; height: 136px; margin-top: 8px; }
.numbox { margin-left: auto; margin-top: 46px; width: 300px; border: 1px solid #999; text-align: center; padding: 18px 0; font-size: 15px; }
.numbox .b { font-weight: bold; font-size: 13px; }
.annul { text-align: center; color: #c62828; font-weight: bold; font-size: 16px; border: 2px solid #c62828; padding: 4px; margin: 10px 0; }
.mid { display: flex; justify-content: space-between; align-items: flex-start; gap: 40px; margin-top: 6px; }
.box { border-collapse: collapse; }
.box th, .box td { border: 1px solid #bbb; padding: 3px 4px; }
.box th { background: #e6e6e6; text-align: left; font-weight: bold; }
.party { width: 600px; margin-top: 62px; }
.party th { width: 80px; }
.dates { width: 300px; }
.dates th, .dates td { height: 42px; }
.dates td { text-align: center; }
.items { width: 100%; margin-top: 8px; }
.items th { text-align: center; }
.w-i { width: 80px; } .w-q { width: 140px; } .w-t { width: 180px; }
.c { text-align: center; } .r { text-align: right; }
.count { margin: 8px 0 14px; }
.foot { display: flex; justify-content: space-between; align-items: flex-start; gap: 30px; }
.left { flex: 1; }
.left p { margin: 0 0 3px; }
.in { padding-left: 10px; }
.pay { display: grid; grid-template-columns: 1fr 60px 120px; }
.pay span:last-child { text-align: right; }
.totals { width: 300px; }
.totals th { width: 150px; }
.totals th.n { background: #fff; font-weight: normal; }
.totals td { text-align: right; height: 28px; }
.totals .tt th, .totals .tt td { background: #e6e6e6; font-weight: bold; }
.obs { margin: 16px 0 26px; }
.codes { text-align: center; font-size: 8px; }
.codes p { margin: 2px 0; }
@media print { .sheet { margin: 0; width: auto; page-break-after: always; } }
/* Tirilla de impresora POS (80 mm): sin logo ni texto lateral, bloques uno debajo del otro. */
@media (max-width: 480px) {
    .sheet { width: auto; margin: 0; padding: 2px 6px 10px 8px; font: 11px/1.3 Arial, Helvetica, sans-serif; color: #000; }
    .side, .logo { display: none; }
    .top { flex-wrap: wrap; justify-content: space-between; gap: 4px; padding-left: 0; min-height: 0; }
    .company { flex: 1; padding-top: 4px; }
    .company p { margin: 0; }
    .qr { width: 96px; height: 96px; margin-top: 0; }
    .numbox { width: 100%; margin: 6px 0 0; padding: 4px 0; font-size: 12px; }
    .mid { flex-direction: column; gap: 6px; }
    .party, .dates, .totals { width: 100%; margin-top: 6px; }
    .party th { width: 56px; }
    .dates th, .dates td { height: auto; }
    .box th, .box td { padding: 2px 3px; }
    .w-i { width: 28px; } .w-q { width: 52px; } .w-t { width: 76px; }
    .foot { flex-direction: column; gap: 6px; }
    .pay { grid-template-columns: 1fr 10px 76px; }
    .obs { margin: 8px 0 10px; }
    .codes { word-break: break-all; }
}
</style>
