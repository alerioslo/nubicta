<script setup>
/* Hoja imprimible de un comprobante contable: encabezado de la empresa y el detalle de cuentas, terceros, débitos y créditos. */
const props = defineProps({ doc: Object, company: Object });
const d = props.doc;
const c = props.company ?? {};
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const date = (v) => (v ? v.split('-').reverse().join('/') : '');
const total = (k) => d.journal.reduce((a, l) => a + Number(l[k] || 0), 0);
</script>

<template>
    <article class="js">
        <p v-if="d.status === 'annulled'" class="annul">ANULADO</p>
        <header class="top">
            <div class="logo"><img v-if="c.logo" :src="c.logo" alt="" /></div>
            <div class="company">
                <h1>{{ c.name }}</h1>
                <p>NIT {{ c.nit }}</p>
                <p>{{ c.address }}</p>
                <p>{{ c.city }} - Colombia</p>
            </div>
            <div class="docbox">
                <p class="t">{{ d.type_name }}</p>
                <p>No. {{ d.name }}</p>
                <p>Fecha: {{ date(d.date) }}</p>
            </div>
        </header>
        <table class="lines">
            <thead><tr><th>Cuenta contable</th><th>Tercero</th><th>Detalle</th><th>Descripción</th><th class="r">Débito</th><th class="r">Crédito</th></tr></thead>
            <tbody>
                <tr v-for="(l, k) in d.journal" :key="k">
                    <td>{{ l.account }}</td><td>{{ l.contact }}</td><td>{{ l.detail }}</td><td>{{ l.description }}</td>
                    <td class="r">{{ l.debit ? money(l.debit) : '' }}</td><td class="r">{{ l.credit ? money(l.credit) : '' }}</td>
                </tr>
                <tr class="tt"><td colspan="4" class="r">Total</td><td class="r">{{ money(total('debit')) }}</td><td class="r">{{ money(total('credit')) }}</td></tr>
            </tbody>
        </table>
        <p v-if="d.observations" class="obs"><b>Observaciones:</b> {{ d.observations }}</p>
        <div class="signs"><p>Elaborado</p><p>Revisado</p><p>Aprobado</p></div>
    </article>
</template>

<style scoped>
.js { position: relative; width: 816px; margin: 0 auto 30px; padding: 24px; background: #fff; border: 1px solid #e3e6ea; font: 11px/1.45 Nunito, Arial, sans-serif; color: #2c2c2c; }
.annul { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 60px; font-weight: 800; color: #c6282833; transform: rotate(-18deg); }
.top { display: grid; grid-template-columns: 170px 1fr 220px; align-items: center; gap: 8px; }
.logo img { max-width: 110px; max-height: 80px; margin: 0 auto; display: block; }
.company { text-align: center; }
.company h1 { font-size: 14px; font-weight: 700; }
.docbox { border: 1px solid #555; padding: 10px; text-align: center; font-size: 12px; }
.docbox .t { font-weight: 700; }
.lines { width: 100%; border-collapse: collapse; margin-top: 18px; }
.lines th { background: #f4f5f7; font-weight: 400; padding: 5px; border: 1px solid #e3e6ea; text-align: left; }
.lines td { padding: 5px; border: 1px solid #e3e6ea; }
.lines .tt td { font-weight: 700; background: #f4f5f7; }
.r { text-align: right !important; }
.obs { margin-top: 14px; }
.signs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 40px; margin-top: 60px; text-align: center; }
.signs p { border-top: 1px solid #555; padding-top: 4px; }
@media print { .js { border: 0; } }
</style>
