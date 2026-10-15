<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import DocumentSheet from '../../Components/DocumentSheet.vue';

/* Detalle de un documento con su contabilizacion, movimientos de inventario y acciones (editar, anular, imprimir, XML).
   Los documentos de venta se ven como en Siigo: estado DIAN y correo arriba y la representacion grafica debajo. */
const props = defineProps({ doc: { type: Object, required: true }, company: Object, resolution: Object });
const d = props.doc;
const sale = !!props.company;
const SHORT = { FV: 'Factura de venta', NC: 'Nota crédito', ND: 'Nota débito', CT: 'Cotización', RM: 'Remisión' };
const menu = ref('');
const showLedger = ref(false);
const mailed = ['sent', 'read'].includes(d.mail_status);
const steps = computed(() => d.dian ? [
    { label: 'Documento guardado', ok: true },
    { label: 'Enviado a la DIAN', ok: ['Sent', 'Accepted', 'Rejected'].includes(d.dian.status) },
    { label: d.dian.status === 'Rejected' ? 'Rechazado por la DIAN' : 'Aprobado por la DIAN', ok: d.dian.status === 'Accepted', error: d.dian.status === 'Rejected' },
    { label: 'Enviado por Mail', ok: mailed, warn: !mailed },
] : []);
const page = usePage();
const error = computed(() => page.props.errors?.items);
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
const back = { ventas: '/ventas', compras: '/compras', contabilidad: '/contabilidad/comprobantes', bancos: '/bancos', inventario: '/productos/documentos' }[d.module_name] ?? '/';
const annulled = d.status === 'annulled';
const STATUS = { Accepted: 'Aceptado por la DIAN', Rejected: 'Rechazado por la DIAN', Sent: 'Enviado, pendiente de respuesta', Draft: 'Pendiente de envío', Pending: 'En cola: la DIAN no respondió, se reintenta automáticamente' };
const ledgerTotal = (k) => d.ledger.reduce((a, l) => a + l[k], 0);

function annul() {
    if (!confirm(`¿Anular el documento ${d.name}? Se reversará su contabilización, inventario y saldos.`)) return;
    router.post(`/documentos/${d.kind}/${d.id}/anular`, {}, { preserveScroll: true });
}
function sendMail() {
    const to = prompt('Correo del destinatario', d.contact?.email ?? '');
    if (to) router.post(`/documentos/${d.kind}/${d.id}/correo`, { to }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="d.name" />
    <div v-if="sale" class="mx-auto max-w-[1000px] pb-[30px]" @click="menu = ''">
        <h1 class="text-[20px] font-bold text-ink">{{ SHORT[d.type] ?? d.type_name }} : {{ d.name }}</h1>
        <p v-if="d.created?.by || d.created?.at" class="mt-[6px] text-[12px] text-ink">Creado: {{ d.created.by }} {{ d.created.at }} hrs.</p>
        <div class="mt-[8px] flex items-center justify-between">
            <div class="flex gap-[10px]">
                <Link v-if="d.editable && !d.blocker" :href="`/documentos/${d.kind}/${d.id}/editar`" class="s-btn s-btn-outline h-[34px] px-[16px] text-[13px]">Editar</Link>
                <div class="relative" @click.stop>
                    <button type="button" class="s-btn s-btn-outline h-[34px] px-[16px] text-[13px]" @click="menu = menu === 'mas' ? '' : 'mas'">Más <Icon icon="caret-down" class="ml-[6px]" /></button>
                    <div v-if="menu === 'mas'" class="absolute left-0 top-[38px] z-20 min-w-[210px] rounded-[4px] border border-line bg-white py-[4px] text-[13px] shadow-lg">
                        <Link v-if="d.copy_url" :href="d.copy_url" class="block px-[14px] py-[7px] hover:bg-[#f0f7ff]">Copiar</Link>
                        <button v-if="!d.blocker && !annulled" type="button" class="block w-full px-[14px] py-[7px] text-left hover:bg-[#f0f7ff]" @click="annul">Anular</button>
                        <button type="button" class="block w-full px-[14px] py-[7px] text-left hover:bg-[#f0f7ff]" @click="showLedger = !showLedger; menu = ''">{{ showLedger ? 'Ocultar contabilización' : 'Ver contabilización' }}</button>
                        <a v-if="d.dian?.xml" :href="`/documentos/${d.kind}/${d.id}/xml`" class="block px-[14px] py-[7px] hover:bg-[#f0f7ff]">Descargar XML</a>
                        <a v-if="d.dian?.response" :href="`/documentos/${d.kind}/${d.id}/xml?tipo=respuesta`" class="block px-[14px] py-[7px] hover:bg-[#f0f7ff]">Respuesta DIAN</a>
                        <a v-if="d.dian?.attached" :href="`/documentos/${d.kind}/${d.id}/xml?tipo=contenedor`" class="block px-[14px] py-[7px] hover:bg-[#f0f7ff]">AttachedDocument</a>
                    </div>
                </div>
            </div>
            <div class="flex gap-[10px]" @click.stop>
                <a :href="`/documentos/${d.kind}/${d.id}/imprimir`" target="_blank" class="icon-btn" title="Descargar PDF"><Icon icon="file" /></a>
                <a :href="`/documentos/${d.kind}/${d.id}/imprimir`" target="_blank" class="icon-btn" title="Imprimir"><Icon icon="print" /></a>
                <div class="relative">
                    <button type="button" class="icon-btn" title="Observaciones" @click="menu = menu === 'obs' ? '' : 'obs'"><Icon icon="comment" /></button>
                    <div v-if="menu === 'obs'" class="absolute right-0 top-[38px] z-20 w-[300px] rounded-[4px] border border-line bg-white p-[12px] text-[12px] shadow-lg">{{ d.observations || 'El documento no tiene observaciones.' }}</div>
                </div>
                <div class="relative">
                    <button type="button" class="icon-btn" title="Archivos adjuntos" @click="menu = menu === 'adj' ? '' : 'adj'"><Icon icon="paperclip" /></button>
                    <div v-if="menu === 'adj'" class="absolute right-0 top-[38px] z-20 w-[300px] rounded-[4px] border border-line bg-white p-[12px] text-[12px] shadow-lg">
                        <a v-for="a in d.attachments" :key="a.id" :href="a.url" class="block py-[3px] text-primary hover:underline"><Icon icon="paperclip" /> {{ a.name }}</a>
                        <p v-if="!d.attachments?.length">El documento no tiene archivos adjuntos.</p>
                    </div>
                </div>
                <div class="relative">
                    <button v-if="d.mailable && !annulled" type="button" class="icon-btn" title="Enviar por correo" @click="sendMail"><Icon icon="envelope" /></button>
                    <span v-if="d.dian && !mailed" class="absolute -bottom-[16px] right-[-14px] whitespace-nowrap rounded-[10px] bg-[#f7941d] px-[8px] text-[10px] font-semibold leading-[16px] text-white">SIN ENVIAR</span>
                </div>
            </div>
        </div>

        <div v-if="steps.length" class="mt-[8px] rounded-[4px] bg-white px-[24px] pb-[10px] pt-[14px]">
            <div class="flex items-center">
                <template v-for="(st, k) in steps" :key="st.label">
                    <span class="flex h-[22px] w-[22px] shrink-0 items-center justify-center rounded-full text-[11px] text-white" :class="st.error ? 'bg-[#e53935]' : st.ok ? 'bg-[#43a047]' : st.warn ? 'bg-[#f7941d]' : 'bg-[#bdbdbd]'">
                        <Icon :icon="st.error ? 'xmark' : st.ok ? 'check' : st.warn ? 'triangle-exclamation' : 'check'" />
                    </span>
                    <span v-if="k < steps.length - 1" class="h-[3px] flex-1" :class="steps[k + 1].ok || steps[k + 1].warn ? 'bg-[#43a047]' : 'bg-[#bdbdbd]'" />
                </template>
            </div>
            <div class="mt-[4px] flex justify-between text-[10px] text-ink">
                <span v-for="(st, k) in steps" :key="st.label" :class="{ 'font-bold': k === steps.length - 1 }">{{ st.label }}</span>
            </div>
        </div>
        <div v-if="annulled" class="mt-[10px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">Documento anulado<span v-if="d.annulled_at"> el {{ d.annulled_at }}</span>.</div>
        <div v-else-if="d.dian?.status === 'Accepted' && !mailed && d.mailable" class="mt-[10px] flex items-center gap-[8px] rounded-[2px] border border-[#f7941d] bg-[#fff6ea] px-[14px] py-[8px] text-[12px] text-ink">
            <Icon icon="triangle-exclamation" class="text-[#f7941d]" /> El documento fue aprobado por la DIAN y está pendiente por enviar a tu cliente.
            <button type="button" class="text-primary hover:underline" @click="sendMail">Enviar por email</button>
        </div>
        <div v-else-if="d.dian?.status === 'Rejected'" class="mt-[10px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">{{ d.dian.message || 'El documento fue rechazado por la DIAN.' }}</div>
        <div v-if="error" class="mt-[10px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">{{ error }}</div>

        <div class="mt-[10px]"><DocumentSheet :doc="d" :company="company" :resolution="resolution" /></div>

        <section v-if="showLedger" class="s-card mt-[14px]">
            <h2 class="px-4 pt-3 text-[13px] font-bold">Contabilización</h2>
            <p v-if="!d.ledger.length" class="p-4 text-[12px] text-muted">{{ annulled ? 'El documento anulado no tiene contabilización.' : 'Este documento no genera contabilización.' }}</p>
            <table v-else class="s-table">
                <thead><tr><th>Cuenta</th><th>Tercero</th><th>Descripción</th><th class="text-right">Débito</th><th class="text-right">Crédito</th></tr></thead>
                <tbody>
                    <tr v-for="(l, k) in d.ledger" :key="k"><td>{{ l.account }}</td><td>{{ l.contact }}</td><td>{{ l.description }}</td><td class="text-right">{{ l.debit ? money(l.debit) : '' }}</td><td class="text-right">{{ l.credit ? money(l.credit) : '' }}</td></tr>
                    <tr class="font-bold"><td colspan="3" class="text-right">Totales</td><td class="text-right">{{ money(ledgerTotal('debit')) }}</td><td class="text-right">{{ money(ledgerTotal('credit')) }}</td></tr>
                </tbody>
            </table>
        </section>
    </div>
    <template v-else>
    <PageHeader :title="`${d.type_name ?? 'Documento'} ${d.name}`" :back="back">
        <template #actions>
            <a v-if="d.printable" :href="`/documentos/${d.kind}/${d.id}/imprimir`" target="_blank" class="s-btn s-btn-outline"><Icon icon="print" /> Imprimir / PDF</a>
            <a v-if="d.dian?.xml" :href="`/documentos/${d.kind}/${d.id}/xml`" class="s-btn s-btn-outline"><Icon icon="file-code" /> XML</a>
            <button v-if="d.mailable && !annulled" type="button" class="s-btn s-btn-outline" @click="sendMail"><Icon icon="envelope" /> {{ ['sent', 'read', 'failed', 'failed_delivery'].includes(d.mail_status) ? 'Reenviar correo' : 'Enviar por correo' }}</button>
            <button v-if="d.favorite !== null" type="button" class="s-btn s-btn-outline" :title="d.favorite ? 'Quitar de favoritos' : 'Marcar como favorito para copiarlo después'" @click="router.post(`/documentos/${d.kind}/${d.id}/favorito`, {}, { preserveScroll: true })">
                <Icon icon="circle-check" :class="d.favorite && 'text-[#f5a623]'" /> {{ d.favorite ? 'Favorito' : 'Marcar favorito' }}
            </button>
            <Link v-if="d.copy_url" :href="d.copy_url" class="s-btn s-btn-outline"><Icon icon="file-lines" /> Copiar</Link>
            <template v-if="!d.blocker">
                <Link v-if="d.editable" :href="`/documentos/${d.kind}/${d.id}/editar`" class="s-btn s-btn-outline"><Icon icon="pen" /> Editar</Link>
                <button type="button" class="s-btn s-btn-outline text-[#c62828]" @click="annul"><Icon icon="ban" /> Anular</button>
            </template>
        </template>
    </PageHeader>

    <div v-if="annulled" class="mt-[14px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">Documento anulado<span v-if="d.annulled_at"> el {{ d.annulled_at }}</span>.</div>
    <div v-else-if="d.blocker && d.source === 'erp'" class="mt-[14px] rounded-[2px] border border-[#ffcc80] bg-[#fff8e1] px-4 py-2 text-[12px] text-[#8d6e00]">{{ d.blocker }}</div>
    <div v-if="error" class="mt-[14px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">{{ error }}</div>

    <section class="s-card mt-[14px] grid grid-cols-2 gap-x-8 gap-y-1 p-4 text-[12px] md:grid-cols-3">
        <p><b>Fecha:</b> {{ d.date }}</p>
        <p v-if="d.due_date"><b>Vencimiento:</b> {{ d.due_date }}</p>
        <p v-if="d.contact"><b>Tercero:</b> {{ d.contact.identification }} - {{ d.contact.name }}</p>
        <p v-if="d.seller"><b>Vendedor:</b> {{ d.seller }}</p>
        <p v-if="d.related"><b>Documento relacionado:</b> <Link v-if="d.related_url" :href="d.related_url" class="text-primary">{{ d.related }}</Link><span v-else>{{ d.related }}</span></p>
        <p v-if="d.supplier_invoice"><b>Factura proveedor:</b> {{ d.supplier_invoice }}</p>
        <p v-if="d.reason"><b>Motivo:</b> {{ d.reason }}</p>
        <p v-if="d.payment"><b>Forma de pago:</b> {{ d.payment }}</p>
        <p><b>Origen:</b> {{ d.source === 'erp' ? 'ERP' : 'Importado de Siigo' }}</p>
        <p v-if="d.observations" class="col-span-full"><b>Observaciones:</b> {{ d.observations }}</p>
        <p v-if="d.attachments?.length" class="col-span-full"><b>Archivos adjuntos:</b>
            <a v-for="a in d.attachments" :key="a.id" :href="a.url" class="ml-[8px] text-primary hover:underline"><Icon icon="paperclip" /> {{ a.name }}</a></p>
    </section>

    <section v-if="d.dian" class="s-card mt-[14px] p-4 text-[12px]">
        <p><b>Estado DIAN:</b> {{ STATUS[d.dian.status] ?? d.dian.status }}</p>
        <p v-if="d.dian.cufe" class="mt-1 break-all"><b>CUFE / CUDS:</b> {{ d.dian.cufe }}</p>
        <p v-if="d.dian.message" class="mt-1 text-muted">{{ d.dian.message }}</p>
        <p v-if="d.dian.response || d.dian.attached" class="mt-2 flex gap-[14px]">
            <a v-if="d.dian.response" :href="`/documentos/${d.kind}/${d.id}/xml?tipo=respuesta`" class="text-primary hover:underline"><Icon icon="file-code" /> Respuesta DIAN (ApplicationResponse)</a>
            <a v-if="d.dian.attached" :href="`/documentos/${d.kind}/${d.id}/xml?tipo=contenedor`" class="text-primary hover:underline"><Icon icon="file-code" /> AttachedDocument</a>
        </p>
    </section>

    <section v-if="d.items?.length" class="s-card mt-[14px]">
        <table class="s-table">
            <thead v-if="d.kind === 'recibos'"><tr><th>Documento</th><th>Descripción</th><th>Vencimiento</th><th class="text-right">Valor</th></tr></thead>
            <thead v-else><tr><th>Código</th><th>Descripción</th><th class="text-right">Cantidad</th><th class="text-right">Valor unitario</th><th class="text-right">Descuento</th><th>Impuestos</th><th class="text-right">Total</th></tr></thead>
            <tbody>
                <tr v-for="(i, k) in d.items" :key="k">
                    <template v-if="d.kind === 'recibos'">
                        <td><Link v-if="i.url" :href="i.url" class="text-primary">{{ i.code }}</Link><span v-else>{{ i.code }}</span></td>
                        <td>{{ i.description }}</td><td>{{ i.due_date }}</td><td class="text-right">{{ money(i.total) }}</td>
                    </template>
                    <template v-else>
                        <td>{{ i.code }}</td><td>{{ i.description }}</td><td class="text-right">{{ qty(i.quantity) }}</td><td class="text-right">{{ money(i.price) }}</td>
                        <td class="text-right">{{ money(i.discount) }}</td><td class="text-[11px]">{{ i.taxes }}</td><td class="text-right">{{ money(i.total) }}</td>
                    </template>
                </tr>
            </tbody>
        </table>
        <div v-if="d.totals?.subtotal !== undefined" class="ml-auto w-[320px] p-4 text-[12px]">
            <p class="flex justify-between"><span>Subtotal</span><span>{{ money(d.totals.subtotal) }}</span></p>
            <p v-if="d.totals.discount" class="flex justify-between"><span>Descuento</span><span>{{ money(d.totals.discount) }}</span></p>
            <p class="flex justify-between"><span>Impuestos</span><span>{{ money(d.totals.tax) }}</span></p>
            <p v-if="d.totals.withholding" class="flex justify-between"><span>Retenciones</span><span>-{{ money(d.totals.withholding) }}</span></p>
            <p v-for="r in d.retentions" :key="r.name" class="flex justify-between"><span>{{ r.name }}</span><span>-{{ money(r.value) }}</span></p>
            <p class="mt-1 flex justify-between border-t pt-1 text-[14px] font-bold"><span>Total</span><span>{{ money(d.totals.total) }}</span></p>
            <p class="flex justify-between text-muted"><span>Saldo pendiente</span><span>{{ money(d.totals.balance) }}</span></p>
        </div>
        <div v-else class="p-4 text-right text-[14px] font-bold">Total {{ money(d.totals.total) }}</div>
    </section>

    <section v-if="d.payments?.length" class="s-card mt-[14px]">
        <h2 class="px-4 pt-3 text-[13px] font-bold">Formas de pago</h2>
        <table class="s-table"><tbody>
            <tr v-for="(p, k) in d.payments" :key="k"><td>{{ p.name }}</td><td>{{ p.due_date }}</td><td class="text-right">{{ money(p.value) }}</td></tr>
        </tbody></table>
    </section>

    <section v-if="d.journal?.length" class="s-card mt-[14px]">
        <h2 class="px-4 pt-3 text-[13px] font-bold">Detalle del comprobante</h2>
        <table class="s-table">
            <thead><tr><th>Cuenta</th><th>Tercero</th><th>Detalle contable</th><th>Descripción</th><th class="text-right">Débito</th><th class="text-right">Crédito</th></tr></thead>
            <tbody><tr v-for="(l, k) in d.journal" :key="k"><td>{{ l.account }}</td><td>{{ l.contact }}</td><td>{{ l.detail }}</td><td>{{ l.description }}</td><td class="text-right">{{ l.debit ? money(l.debit) : '' }}</td><td class="text-right">{{ l.credit ? money(l.credit) : '' }}</td></tr></tbody>
        </table>
    </section>

    <section class="s-card mt-[14px]">
        <h2 class="px-4 pt-3 text-[13px] font-bold">Contabilización</h2>
        <p v-if="!d.ledger.length" class="p-4 text-[12px] text-muted">{{ annulled ? 'El documento anulado no tiene contabilización.' : 'Este documento no genera contabilización.' }}</p>
        <table v-else class="s-table">
            <thead><tr><th>Cuenta</th><th>Tercero</th><th>Descripción</th><th class="text-right">Débito</th><th class="text-right">Crédito</th></tr></thead>
            <tbody>
                <tr v-for="(l, k) in d.ledger" :key="k"><td>{{ l.account }}</td><td>{{ l.contact }}</td><td>{{ l.description }}</td><td class="text-right">{{ l.debit ? money(l.debit) : '' }}</td><td class="text-right">{{ l.credit ? money(l.credit) : '' }}</td></tr>
                <tr class="font-bold"><td colspan="3" class="text-right">Totales</td><td class="text-right">{{ money(ledgerTotal('debit')) }}</td><td class="text-right">{{ money(ledgerTotal('credit')) }}</td></tr>
            </tbody>
        </table>
    </section>

    <section v-if="d.movements.length" class="s-card mt-[14px]">
        <h2 class="px-4 pt-3 text-[13px] font-bold">Movimientos de inventario</h2>
        <table class="s-table">
            <thead><tr><th>Fecha</th><th>Producto</th><th>Bodega</th><th>Concepto</th><th class="text-right">Cantidad</th><th class="text-right">Costo unitario</th><th class="text-right">Saldo</th></tr></thead>
            <tbody><tr v-for="(m, k) in d.movements" :key="k"><td>{{ m.date }}</td><td>{{ m.product }}</td><td>{{ m.warehouse }}</td><td>{{ m.concept }}</td><td class="text-right">{{ qty(m.quantity) }}</td><td class="text-right">{{ money(m.unit_cost) }}</td><td class="text-right">{{ qty(m.balance) }}</td></tr></tbody>
        </table>
    </section>
    </template>
</template>

<style scoped>
.icon-btn { display: inline-flex; height: 34px; width: 34px; align-items: center; justify-content: center; border-radius: 9999px; border: 1px solid #d5dbe3; background: #fff; color: #0b4f8a; font-size: 14px; }
.icon-btn:hover { background: #f0f7ff; }
</style>
