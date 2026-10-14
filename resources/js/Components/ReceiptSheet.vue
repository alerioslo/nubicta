<script setup>
/* Representacion grafica de recibos de caja y de pago, igual a la hoja que muestra Siigo al abrir el recibo. */
const props = defineProps({ doc: Object, company: Object });
const d = props.doc;
const c = props.company ?? {};
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const nit = (n, dv) => (n ? Number(String(n).replace(/\D/g, '')).toLocaleString('es-CO') : '') + (dv !== null && dv !== undefined && dv !== '' ? `-${dv}` : '');
const companyNit = () => {
    const [n, dv] = String(c.nit ?? '').split('-');
    return nit(n, dv);
};
const date = (v) => (v ? v.split('-').reverse().join('/') : '');
const number = d.name.split('-').pop();
</script>

<template>
    <article class="rs">
        <p v-if="d.status === 'annulled'" class="annul">ANULADO</p>
        <header class="top">
            <div class="logo"><img v-if="c.logo" :src="c.logo" alt="" /></div>
            <div class="company">
                <h1>{{ c.name }}</h1>
                <p>NIT {{ companyNit() }}</p>
                <p>{{ c.address }}</p>
                <p v-if="c.phone">Tel: ({{ c.phone2 || c.phone }}) {{ c.phone }}</p>
                <p>{{ c.city }} - Colombia</p>
                <p>{{ c.email }}</p>
            </div>
            <div class="docbox">
                <p class="t">{{ d.type_name }}</p>
                <p>No. {{ number }}</p>
            </div>
        </header>

        <section class="party">
            <table class="who">
                <tr><th>Señores</th><td colspan="3">{{ d.contact?.name }}</td></tr>
                <tr><th>NIT</th><td>{{ nit(d.contact?.identification, d.contact_extra?.dv) }}</td><th>Teléfono</th><td>{{ d.contact_extra?.phone }}</td></tr>
                <tr><th>Dirección</th><td>{{ d.contact?.address }}</td><th>Ciudad</th><td>{{ d.contact_extra?.city }} - Colombia</td></tr>
            </table>
            <div class="when">
                <p class="h">Fecha de recibo</p>
                <p class="v">{{ date(d.date) }}</p>
            </div>
        </section>

        <table class="items">
            <thead><tr><th class="c">Ítem</th><th>Documento</th><th>Descripción</th><th class="r">Valor</th></tr></thead>
            <tbody>
                <template v-for="(i, k) in d.items" :key="k">
                    <tr>
                        <td class="c">{{ k + 1 }}</td><td>{{ i.code }}</td><td>{{ i.description }}</td><td class="r">{{ money(i.total) }}</td>
                    </tr>
                    <tr v-for="(a, j) in i.adjustments" :key="`${k}-${j}`" class="adj">
                        <td></td><td></td><td>{{ a.name }}</td><td class="r">-{{ money(a.value) }}</td>
                    </tr>
                </template>
            </tbody>
        </table>

        <section class="bottom">
            <div class="left">
                <p><b>Total ítems:</b> {{ d.items.length }}</p>
                <p class="mt"><b>Valor en letras:</b></p>
                <p class="ind">{{ d.words }}</p>
            </div>
            <table class="total">
                <tr><th>Total pago</th><td>COP {{ money(d.totals.total) }}</td></tr>
            </table>
            <div>
                <p><b>Condiciones de pago:</b></p>
                <p class="ind">{{ d.payment }}</p>
            </div>
            <p class="pv">COP {{ money(d.totals.total) }}</p>
            <div class="obs">
                <p><b>Observaciones:</b></p>
                <p class="ind">{{ d.observations }}</p>
            </div>
        </section>
    </article>
</template>

<style scoped>
.rs { position: relative; width: 590px; margin: 0 auto; padding: 18px 18px 40px; background: #fff; border: 1px solid #e3e6ea; border-radius: 4px; font: 11px/1.45 Nunito, Arial, sans-serif; color: #2c2c2c; }
.annul { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 60px; font-weight: 800; color: #c6282833; transform: rotate(-18deg); pointer-events: none; }
.top { display: grid; grid-template-columns: 170px 1fr 190px; align-items: center; gap: 8px; }
.logo img { max-width: 110px; max-height: 80px; margin: 0 auto; display: block; }
.company { text-align: center; }
.company h1 { font-size: 14px; font-weight: 700; margin-bottom: 4px; }
.docbox { border: 1px solid #555; padding: 10px; text-align: center; font-size: 13px; }
.docbox .t { font-weight: 700; }
.party { display: grid; grid-template-columns: 1fr 180px; gap: 22px; margin-top: 14px; }
.who { width: 100%; border-collapse: collapse; }
.who th, .who td { border: 1px solid #e3e6ea; padding: 4px 5px; text-align: left; font-weight: 400; }
.who th { background: #f4f5f7; width: 58px; }
.when { border: 1px solid #e3e6ea; }
.when .h { background: #f4f5f7; text-align: center; padding: 6px; border-bottom: 1px solid #e3e6ea; }
.when .v { text-align: center; padding: 26px 0; }
.items { width: 100%; border-collapse: collapse; margin-top: 14px; }
.items th { background: #f4f5f7; font-weight: 400; padding: 5px; border: 1px solid #e3e6ea; }
.items td { padding: 5px; border: 1px solid #e3e6ea; }
.items .adj td { color: #555; }
.c { text-align: center; }
.r { text-align: right; }
.bottom { display: grid; grid-template-columns: 1fr 274px; gap: 6px 16px; margin-top: 18px; }
.left .mt { margin-top: 6px; }
.ind { padding-left: 7px; }
.pv { align-self: center; padding-top: 10px; }
.obs { grid-column: 1 / -1; margin-top: 18px; }
.total { width: 100%; border-collapse: collapse; align-self: start; }
.total th, .total td { background: #ececec; border: 1px solid #d9d9d9; padding: 7px; font-weight: 700; text-align: left; }
.total td { text-align: right; }
@media print { .rs { border: 0; } }
</style>
