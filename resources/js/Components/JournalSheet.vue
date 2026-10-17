<script setup>
/* Hoja de un comprobante contable: empresa al centro, recuadro con numero y fecha, y el detalle de cuentas. */
const props = defineProps({ doc: Object, company: Object });
const d = props.doc;
const c = props.company ?? {};
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const dots = (v) => String(v ?? '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
const nit = c.nit_number ? dots(c.nit_number) + (c.dv !== null && c.dv !== undefined && c.dv !== '' ? `-${c.dv}` : '') : c.nit;
const phones = [c.phone, c.phone2].filter(Boolean).filter((p, i, a) => a.indexOf(p) === i);
const total = (k) => d.journal.reduce((a, l) => a + Number(l[k] || 0), 0);
</script>

<template>
    <article class="js">
        <p v-if="d.source === 'siigo'" class="side">Elaborado por Siigo S.A.S 830.048.145-8</p>
        <div v-if="d.status === 'annulled'" class="ribbon"><span>Anulada</span></div>
        <header class="top">
            <div class="logo"><img v-if="c.logo" :src="c.logo" alt="" /></div>
            <div class="company">
                <b>{{ c.name }}</b>
                <p>NIT {{ nit }}</p>
                <p>{{ c.address }}</p>
                <p v-if="phones.length">Teléfono: ({{ phones[0] }}) {{ phones.slice(1).join(' ') || phones[0] }}</p>
                <p v-if="c.city">{{ c.city }} - Colombia</p>
            </div>
            <table class="docbox">
                <tr><th>{{ d.type_name }} No.</th><td>{{ d.name }}</td></tr>
                <tr><th>Fecha de elaboración</th><td>{{ d.date }}</td></tr>
            </table>
        </header>
        <table class="lines">
            <thead><tr><th class="n">#</th><th>Cuenta contable</th><th>Tercero</th><th>Detalle</th><th>Descripción</th><th>Débito</th><th>Crédito</th></tr></thead>
            <tbody>
                <tr v-for="(l, k) in d.journal" :key="k">
                    <td class="n">{{ k + 1 }}</td><td>{{ l.account }}</td><td>{{ l.contact }}</td><td class="b">{{ l.detail }}</td><td>{{ l.description }}</td>
                    <td class="r">{{ money(l.debit) }}</td><td class="r">{{ money(l.credit) }}</td>
                </tr>
            </tbody>
        </table>
        <table class="tt">
            <tr><td class="b">Total</td><td class="r">{{ money(total('debit')) }}</td><td class="r">{{ money(total('credit')) }}</td></tr>
        </table>
        <p class="obs"><b>Observaciones</b></p>
        <p class="obs-text">{{ d.observations }}</p>
    </article>
</template>

<style scoped>
.js { position: relative; background: #fff; padding: 18px 36px 40px 18px; font: 10px/1.4 Arial, Helvetica, sans-serif; color: #222; }
.side { position: absolute; right: 6px; top: 60px; writing-mode: vertical-rl; transform: rotate(180deg); font-size: 8px; font-weight: bold; white-space: nowrap; }
.ribbon { position: absolute; left: 0; top: 0; width: 90px; height: 90px; overflow: hidden; }
.ribbon span { position: absolute; left: -34px; top: 24px; width: 140px; transform: rotate(-45deg); background: #ec407a; color: #fff; text-align: center; font-size: 11px; padding: 3px 0; box-shadow: 0 1px 2px #0003; }
.top { display: grid; grid-template-columns: 1fr auto 1fr; align-items: start; margin-bottom: 30px; }
.logo img { max-height: 70px; max-width: 150px; object-fit: contain; margin-left: 30px; }
.company { text-align: center; }
.company b, .company p { display: block; margin: 0; }
table { border-collapse: collapse; }
.docbox { justify-self: end; margin-top: 10px; }
.docbox th, .docbox td { padding: 3px 8px; border-bottom: 1px solid #fff; }
.docbox th { background: #e8e8e8; font-weight: bold; text-align: left; width: 120px; }
.docbox td { background: #f7f7f7; text-align: right; width: 80px; }
.lines { width: 100%; }
.lines th { background: #e8e8e8; font-weight: bold; padding: 4px 6px; border: 1px solid #ddd; text-align: center; }
.lines td { padding: 4px 6px; border: 1px solid #ddd; }
.lines .n { width: 22px; text-align: center; }
.b { font-weight: bold; }
.r { text-align: right; }
.tt { width: 100%; margin-top: 18px; }
.tt td { padding: 2px 6px; }
.tt td:first-child { text-align: right; padding-right: 60px; }
.tt td.r { width: 16%; }
.obs { margin-top: 30px; }
.obs-text { white-space: pre-line; min-height: 20px; }
@media print { .js { padding: 10px; } }
</style>
