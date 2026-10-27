<script setup>
import { printInPlace } from '../../composables/print';
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { alertDialog, confirmDialog, promptDialog } from '../../composables/dialog';
import PageHeader from '../../Components/PageHeader.vue';
import DocumentSheet from '../../Components/DocumentSheet.vue';

/* Detalle de un documento con su contabilizacion, movimientos de inventario y acciones (editar, anular, imprimir, XML).
   Los documentos de venta muestran el estado DIAN y correo arriba y la representacion grafica debajo. */
const props = defineProps({ doc: { type: Object, required: true }, company: Object, resolution: Object });
const d = props.doc;
const sale = d.kind === 'ventas';
const SHORT = { FV: 'Factura de venta', NC: 'Nota crédito', ND: 'Nota débito', CT: 'Cotización', RM: 'Remisión' };
const menu = ref('');
const classic = d.type === 'NC';
const quote = d.type === 'CT';
const QUOTE_STATUS = { approved: 'Aprobada', discarded: 'Descartada' };
const setStatus = (estado) => router.post(`/documentos/${d.kind}/${d.id}/estado`, { estado }, { preserveScroll: true, preserveState: false });
async function removeQuote() {
    if (await confirmDialog(`¿Borrar la cotización ${d.name}?`)) router.post(`/documentos/${d.kind}/${d.id}/eliminar`);
}
function copyUrl() {
    navigator.clipboard?.writeText(d.public_url || window.location.href);
    menu.value = '';
}
const allHistory = ref(false);
const fileInput = ref(null);
const upload = (e) => {
    const file = e.target.files?.[0];
    if (file) router.post(`/documentos/${d.kind}/${d.id}/adjuntos`, { attachment: file }, { forceFormData: true, preserveScroll: true, preserveState: false });
};
const showLedger = ref(false);
const mailed = ['sent', 'read'].includes(d.mail_status);
const simulated = !!d.dian?.simulated;
const steps = computed(() => d.dian ? [
    { label: 'Documento guardado', ok: true },
    { label: simulated ? 'Simulado, no enviado a la DIAN' : 'Enviado a la DIAN', ok: simulated || ['Sent', 'Accepted', 'Rejected'].includes(d.dian.status) },
    { label: simulated ? 'SIMULADO — no enviado a la DIAN' : (d.dian.status === 'Rejected' ? 'Rechazado por la DIAN' : 'Aprobado por la DIAN'), ok: d.dian.status === 'Accepted', error: d.dian.status === 'Rejected' && !simulated },
    { label: classic ? 'Enviado por email' : 'Enviado por Mail', ok: mailed, warn: !mailed },
] : []);
const page = usePage();
const error = computed(() => page.props.errors?.items);
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
const back = { ventas: '/ventas', compras: '/compras', contabilidad: '/contabilidad/comprobantes', bancos: '/bancos', inventario: '/productos/documentos' }[d.module_name] ?? '/';
const annulled = d.status === 'annulled';
const STATUS = { Accepted: 'Aceptado por la DIAN', Rejected: 'Rechazado por la DIAN', Sent: 'Enviado, pendiente de respuesta', Draft: 'Pendiente de envío', Pending: 'En cola: la DIAN no respondió, se reintenta automáticamente' };
const ledgerTotal = (k) => d.ledger.reduce((a, l) => a + l[k], 0);

const blocked = () => alertDialog(`No es posible realizar ajustes\n\n${d.blocker}`);
async function annul() {
    menu.value = '';
    if (d.blocker) return blocked();
    if (!await confirmDialog(`¿Anular el documento ${d.name}? Se reversará su contabilización, inventario y saldos.`)) return;
    router.post(`/documentos/${d.kind}/${d.id}/anular`, {}, { preserveScroll: true, preserveState: false });
}
async function remove() {
    menu.value = '';
    if (d.blocker && !annulled) return blocked();
    if (d.dian?.status === 'Accepted') return alertDialog('No es posible realizar ajustes\n\nLa DIAN ya aceptó este documento electrónico: no se puede eliminar.');
    const effects = annulled ? '' : ' Se reversará su contabilización, inventario y saldos.';
    if (!await confirmDialog(`¿Eliminar definitivamente el documento ${d.name}?${effects}`)) return;
    router.post(`/documentos/${d.kind}/${d.id}/eliminar`, {}, { preserveState: false });
}
const post = (path) => { menu.value = ''; router.post(`/documentos/${d.kind}/${d.id}/${path}`, {}, { preserveScroll: true, preserveState: false }); };
const refreshing = ref(false);
function refreshDian() {
    menu.value = '';
    router.post(`/documentos/${d.kind}/${d.id}/actualizar-dian`, {}, { preserveScroll: true, preserveState: false, onStart: () => { refreshing.value = true; }, onFinish: () => { refreshing.value = false; } });
}
const notify = (text, error = false) => { page.props.flash = error ? { error: text } : { success: text }; };
async function copyLink() {
    menu.value = '';
    const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
    const res = await fetch(`/documentos/${d.kind}/${d.id}/enlace`, { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token } });
    const body = await res.json().catch(() => ({}));
    if (!res.ok || !body.url) return notify(body.message || 'No se pudo generar el enlace del documento.', true);
    try {
        await navigator.clipboard.writeText(body.url);
        notify('Enlace copiado al portapapeles');
    } catch {
        await promptDialog('Copia el enlace del documento', body.url);
    }
}
const invoice = d.type === 'FV' && !annulled && d.source === 'erp';
const electronic = !!d.dian && d.source === 'erp';
const more = computed(() => [
    invoice && { label: 'Aplicar nota crédito', href: `/ventas/notas-credito/crear?factura=${d.id}` },
    invoice && { label: 'Aplicar nota débito', href: `/ventas/notas-debito/crear?factura=${d.id}` },
    electronic && !annulled && { label: 'Actualizar documento electrónico', run: refreshDian },
    d.show_ledger !== false && { label: showLedger.value ? 'Ocultar contabilización' : 'Ver contabilización', run: () => { showLedger.value = !showLedger.value; menu.value = ''; } },
    !annulled && { label: 'Anular', run: annul },
    { label: 'Eliminar', run: remove },
    !annulled && { label: 'Copiar enlace', run: copyLink },
    d.dian?.xml && { label: 'Descargar XML', href: `/documentos/${d.kind}/${d.id}/xml`, download: true },
    d.dian?.response && { label: 'Respuesta DIAN', href: `/documentos/${d.kind}/${d.id}/xml?tipo=respuesta`, download: true },
    d.dian?.attached && { label: 'AttachedDocument', href: `/documentos/${d.kind}/${d.id}/xml?tipo=contenedor`, download: true },
    invoice && { label: 'Crear factura recurrente', href: `/ventas/facturas-recurrentes/crear?factura=${d.id}` },
    ['FV', 'ND'].includes(d.type) && !annulled && { label: 'Actualizar seguimiento de cobranza', run: () => post('cobranza') },
    d.copy_url && { label: 'Copiar', href: d.copy_url },
].filter(Boolean));
const resending = ref(false);
async function resendDian() {
    if (!await confirmDialog(`¿Reenviar ${d.name} a la DIAN?`)) return;
    router.post(`/documentos/${d.kind}/${d.id}/dian`, {}, { preserveScroll: true, preserveState: false, onStart: () => { resending.value = true; }, onFinish: () => { resending.value = false; } });
}
async function sendMail() {
    const to = await promptDialog('Correo del destinatario', d.contact?.email ?? '');
    if (to) router.post(`/documentos/${d.kind}/${d.id}/correo`, { to }, { preserveScroll: true, preserveState: false });
}
</script>

<template>
    <Head :title="d.name" />
    <div v-if="sale" class="mx-auto max-w-[1000px] pb-[30px]" @click="menu = ''">
        <h1 class="text-[20px] font-bold text-ink">{{ SHORT[d.type] ?? d.type_name }}{{ classic || quote ? ':' : ' :' }} {{ d.name }}
            <span v-if="quote && QUOTE_STATUS[d.quote_status]" class="ml-[8px] rounded-[10px] px-[10px] py-[2px] align-middle text-[11px] font-semibold text-white" :class="d.quote_status === 'approved' ? 'bg-[#43a047]' : 'bg-[#9e9e9e]'">{{ QUOTE_STATUS[d.quote_status] }}</span></h1>
        <p class="mt-[6px] flex justify-between text-[12px] text-ink">
            <span v-if="d.created?.at">Creado: {{ [d.created.by, d.created.at].filter(Boolean).join(' ') }} hrs.</span>
            <span v-if="classic">Modificado: {{ d.modified_at }}</span>
        </p>
        <div v-if="classic" class="mt-[14px] flex gap-[12px] border-t border-line pt-[26px]" @click.stop>
            <button type="button" class="s-btn h-[34px] bg-[#e0e0e0] px-[16px] text-[13px] font-semibold text-[#9e9e9e]" disabled><Icon icon="paper-plane" class="mr-[6px]" />Enviar a la DIAN</button>
            <button v-if="d.mailable && !annulled" type="button" class="s-btn s-btn-outline h-[34px] px-[16px] text-[13px] font-semibold" @click="sendMail"><Icon icon="envelope" class="mr-[6px]" />Enviar por mail</button>
            <button type="button" class="s-btn s-btn-outline h-[34px] px-[16px] text-[13px] font-semibold" @click="printInPlace(`/documentos/${d.kind}/${d.id}/imprimir`)"><Icon icon="print" class="mr-[6px]" />Imprimir</button>
            <div class="relative">
                <button type="button" class="s-btn s-btn-outline h-[34px] px-[16px] text-[13px] font-semibold" @click="menu = menu === 'mas' ? '' : 'mas'">Más <Icon icon="caret-down" class="ml-[6px]" /></button>
                <div v-if="menu === 'mas'" class="absolute left-0 top-[38px] z-20 min-w-[210px] rounded-[4px] border border-line bg-white py-[4px] text-[13px] shadow-lg">
                    <template v-for="m in more" :key="m.label">
                        <a v-if="m.download" :href="m.href" class="block px-[14px] py-[7px] hover:bg-[#f0f9ff]">{{ m.label }}</a>
                        <Link v-else-if="m.href" :href="m.href" class="block px-[14px] py-[7px] hover:bg-[#f0f9ff]">{{ m.label }}</Link>
                        <button v-else type="button" class="block w-full px-[14px] py-[7px] text-left hover:bg-[#f0f9ff]" @click="m.run">{{ m.label }}</button>
                    </template>
                </div>
            </div>
        </div>
        <div v-else-if="quote" class="mt-[14px] flex items-center justify-between border-t border-line pt-[26px]" @click.stop>
            <div class="flex items-center gap-[12px]">
                <div class="relative flex">
                    <Link :href="`/ventas/facturas/crear?desde=${d.id}`" class="s-btn h-[34px] rounded-r-none bg-[#6cb42c] px-[16px] text-[13px] font-semibold text-white hover:bg-[#5ea226]">Facturar</Link>
                    <button type="button" class="s-btn h-[34px] rounded-l-none border-l border-[#ffffff66] bg-[#6cb42c] px-[8px] text-white hover:bg-[#5ea226]" @click="menu = menu === 'fac' ? '' : 'fac'"><Icon icon="caret-down" /></button>
                    <div v-if="menu === 'fac'" class="absolute left-[66px] top-[38px] z-20 min-w-[178px] rounded-[4px] bg-white py-[4px] text-[13px] shadow-lg">
                        <Link :href="`/ventas/remisiones/crear?desde=${d.id}`" class="block px-[14px] py-[7px] hover:bg-[#f0f9ff]">Remisionar</Link>
                    </div>
                </div>
                <div class="relative flex">
                    <button type="button" class="s-btn s-btn-outline h-[34px] rounded-r-none px-[16px] text-[13px] font-semibold disabled:opacity-60" :disabled="!!d.blocker" :title="d.blocker" @click="setStatus(d.quote_status === 'approved' ? 'pendiente' : 'aprobar')">{{ d.quote_status === 'approved' ? 'Aprobada' : 'Aprobar' }}</button>
                    <button type="button" class="s-btn s-btn-outline h-[34px] rounded-l-none border-l-0 px-[8px] disabled:opacity-60" :disabled="!!d.blocker" @click="menu = menu === 'apr' ? '' : 'apr'"><Icon icon="caret-down" /></button>
                    <div v-if="menu === 'apr'" class="absolute right-0 top-[38px] z-20 min-w-[178px] rounded-[4px] bg-white py-[4px] text-[13px] shadow-lg">
                        <button v-if="d.quote_status !== 'discarded'" type="button" class="block w-full px-[14px] py-[7px] text-left hover:bg-[#f0f9ff]" @click="setStatus('descartar')">Descartar</button>
                        <button v-else type="button" class="block w-full px-[14px] py-[7px] text-left hover:bg-[#f0f9ff]" @click="setStatus('pendiente')">Volver a pendiente</button>
                    </div>
                </div>
                <span class="h-[26px] border-l border-line"></span>
                <Link v-if="d.editable && !d.blocker" :href="`/documentos/${d.kind}/${d.id}/editar`" class="s-btn s-btn-outline h-[34px] px-[14px] text-[13px] font-semibold"><Icon icon="pen" class="mr-[6px]" />Editar</Link>
                <button v-else type="button" class="s-btn s-btn-outline h-[34px] px-[14px] text-[13px] font-semibold opacity-60" disabled :title="d.blocker"><Icon icon="pen" class="mr-[6px]" />Editar</button>
                <div class="relative">
                    <button type="button" class="s-btn s-btn-outline h-[34px] px-[14px] text-[13px] font-semibold" @click="menu = menu === 'mas' ? '' : 'mas'">Más <Icon icon="caret-down" class="ml-[6px]" /></button>
                    <div v-if="menu === 'mas'" class="absolute left-[-120px] top-[38px] z-20 min-w-[178px] rounded-[4px] bg-white py-[4px] text-[13px] shadow-lg">
                        <Link v-if="d.copy_url" :href="d.copy_url" class="block px-[14px] py-[7px] hover:bg-[#f0f9ff]">Copiar cotización</Link>
                        <button type="button" class="block w-full px-[14px] py-[7px] text-left hover:bg-[#f0f9ff] disabled:text-[#bdbdbd]" :disabled="!!d.blocker" :title="d.blocker" @click="removeQuote">Borrar</button>
                        <Link href="/ventas/recibos/crear" class="block px-[14px] py-[7px] hover:bg-[#f0f9ff]">Registrar anticipo</Link>
                        <Link href="/ventas/seguimiento-comercial/crear" class="block px-[14px] py-[7px] hover:bg-[#f0f9ff]">Crear seguimiento comercial</Link>
                        <button type="button" class="block w-full px-[14px] py-[7px] text-left hover:bg-[#f0f9ff]" @click="copyUrl">Copiar URL cotización</button>
                    </div>
                </div>
            </div>
            <div class="flex gap-[10px]">
                <button type="button" class="icon-btn" title="Imprimir" @click="printInPlace(`/documentos/${d.kind}/${d.id}/imprimir`)"><Icon icon="print" /></button>
                <div class="relative">
                    <button type="button" class="icon-btn" title="Observaciones" @click="menu = menu === 'obs' ? '' : 'obs'"><Icon icon="comment" /></button>
                    <div v-if="menu === 'obs'" class="absolute right-0 top-[38px] z-20 w-[300px] rounded-[4px] border border-line bg-white p-[12px] text-[12px] shadow-lg">{{ d.observations || 'El documento no tiene observaciones.' }}</div>
                </div>
                <button v-if="d.mailable && !annulled" type="button" class="icon-btn" title="Enviar por correo" @click="sendMail"><Icon icon="envelope" /></button>
            </div>
        </div>
        <div v-else class="mt-[8px] flex items-center justify-between">
            <div class="flex gap-[10px]">
                <Link v-if="d.editable && !d.blocker" :href="`/documentos/${d.kind}/${d.id}/editar`" class="s-btn s-btn-outline h-[34px] px-[16px] text-[13px]">Editar</Link>
                <div class="relative" @click.stop>
                    <button type="button" class="s-btn s-btn-outline h-[34px] px-[16px] text-[13px]" @click="menu = menu === 'mas' ? '' : 'mas'">Más <Icon icon="caret-down" class="ml-[6px]" /></button>
                    <div v-if="menu === 'mas'" class="absolute left-0 top-[38px] z-20 min-w-[210px] rounded-[4px] border border-line bg-white py-[4px] text-[13px] shadow-lg">
                        <template v-for="m in more" :key="m.label">
                            <a v-if="m.download" :href="m.href" class="block px-[14px] py-[7px] hover:bg-[#f0f9ff]">{{ m.label }}</a>
                            <Link v-else-if="m.href" :href="m.href" class="block px-[14px] py-[7px] hover:bg-[#f0f9ff]">{{ m.label }}</Link>
                            <button v-else type="button" class="block w-full px-[14px] py-[7px] text-left hover:bg-[#f0f9ff]" @click="m.run">{{ m.label }}</button>
                        </template>
                    </div>
                </div>
            </div>
            <div class="flex gap-[10px]" @click.stop>
                <button type="button" class="icon-btn" title="Descargar PDF" @click="printInPlace(`/documentos/${d.kind}/${d.id}/imprimir`)"><Icon icon="file" /></button>
                <button type="button" class="icon-btn" title="Imprimir" @click="printInPlace(`/documentos/${d.kind}/${d.id}/imprimir`)"><Icon icon="print" /></button>
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

        <p v-if="refreshing" class="mt-[8px] text-[12px] text-ink"><Icon icon="rotate" class="mr-[6px] animate-spin" />Consultando el documento en la DIAN…</p>
        <p v-if="d.collection_task" class="mt-[8px] text-[12px] text-ink">Seguimiento de cobranza:
            <Link :href="`/ventas/seguimiento-comercial/${d.collection_task.id}/editar`" class="font-semibold text-primary hover:underline">{{ d.collection_task.status }}</Link></p>
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
        <div v-else-if="d.dian?.simulated" class="mt-[10px] flex items-center gap-[8px] rounded-[2px] border border-[#e6c200] bg-[#fff8e1] px-[14px] py-[8px] text-[12px] text-ink">
            <Icon icon="triangle-exclamation" class="text-[#a86b00]" /> SIMULADO — no enviado a la DIAN. Este documento no existe ante la DIAN.
        </div>
        <div v-else-if="d.dian?.status === 'Accepted' && mailed" class="mt-[10px] flex items-center gap-[8px] rounded-[2px] border border-[#7cb342] bg-[#f1f8e9] px-[14px] py-[8px] text-[12px] text-ink">
            <Icon icon="circle-check" class="text-[#43a047]" /> El documento electrónico fue aprobado por la DIAN y se envió con CUFE y QR a tu cliente de forma exitosa.
        </div>
        <div v-else-if="d.dian?.status === 'Accepted' && !mailed && d.mailable" class="mt-[10px] flex items-center gap-[8px] rounded-[2px] border border-[#f7941d] bg-[#fff6ea] px-[14px] py-[8px] text-[12px] text-ink">
            <Icon icon="triangle-exclamation" class="text-[#f7941d]" /> El documento fue aprobado por la DIAN y está pendiente por enviar a tu cliente.
            <button type="button" class="text-primary hover:underline" @click="sendMail">Enviar por email</button>
        </div>
        <div v-else-if="['Rejected', 'Draft'].includes(d.dian?.status)" class="mt-[10px] flex items-start gap-[12px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">
            <span class="flex-1">{{ d.dian.message || (d.dian.status === 'Rejected' ? 'El documento fue rechazado por la DIAN.' : 'El documento está pendiente de envío a la DIAN.') }}</span>
            <button type="button" class="s-btn s-btn-outline h-[28px] shrink-0 bg-white px-[12px] text-[12px] font-semibold" :disabled="resending" @click="resendDian"><Icon icon="paper-plane" class="mr-[6px]" />{{ resending ? 'Enviando…' : 'Reenviar a la DIAN' }}</button>
        </div>
        <div v-if="error" class="mt-[10px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">{{ error }}</div>

        <div class="mt-[10px]"><DocumentSheet :doc="d" :company="company" :resolution="resolution" /></div>

        <template v-if="!classic && !quote && d.history?.length">
            <div v-for="h in (allHistory ? d.history : d.history.slice(0, 1))" :key="h.at + h.to" class="mt-[4px] flex justify-between rounded-[4px] border border-[#999] bg-[#f5f5f5] px-[38px] py-[3px] text-[10px] text-ink">
                <span><b>Documento <span class="text-[#1a237e]">enviado</span> a {{ h.name }} ({{ h.to }})</b></span><span>{{ h.at }}</span>
            </div>
            <button v-if="d.history.length > 1" type="button" class="ml-auto block pt-[4px] text-[12px] text-ink hover:underline" @click="allHistory = !allHistory">{{ allHistory ? 'Ver menos' : 'Ver más' }}</button>
        </template>
        <div v-if="quote" class="mt-[10px] grid grid-cols-[1fr_1fr] gap-[10px]">
            <section class="rounded-[4px] bg-white p-[20px]">
                <h2 class="text-[17px] font-semibold text-ink">Información General</h2>
                <table class="mt-[16px] w-full border border-line text-[12px]">
                    <tr><th class="w-[220px] p-[14px] text-left font-semibold">Cliente</th><td class="p-[14px]"><Link v-if="d.contact_id" :href="`/terceros/${d.contact_id}/editar`" class="font-semibold text-primary underline">{{ d.contact?.name }}</Link></td></tr>
                    <tr class="bg-[#f5f5f5]"><th class="p-[14px] text-left font-semibold">Contacto</th><td class="p-[14px]">{{ d.contact_person || d.contact?.name }}</td></tr>
                    <tr><th class="p-[14px] text-left font-semibold">Factura relacionada</th><td class="p-[14px]"><Link v-if="d.invoice_link" :href="d.invoice_link.url" class="font-semibold text-primary underline">{{ d.invoice_link.name }}</Link></td></tr>
                    <tr class="bg-[#f5f5f5]"><th class="p-[14px] text-left font-semibold">Remisión relacionada</th><td class="p-[14px]"><Link v-if="d.remission_link" :href="d.remission_link.url" class="font-semibold text-primary underline">{{ d.remission_link.name }}</Link></td></tr>
                </table>
            </section>
            <section class="rounded-[4px] bg-white p-[20px]">
                <h2 class="text-[17px] font-semibold text-ink">Anexos</h2>
                <a v-for="a in d.attachments" :key="a.id" :href="a.url" class="mt-[10px] block text-[12px] text-primary hover:underline"><Icon icon="paperclip" /> {{ a.name }}</a>
                <button type="button" class="mt-[30px] px-[30px] text-[12px] text-ink" @click="fileInput.click()">Adjuntar archivo <Icon icon="paperclip" class="ml-[6px]" /></button>
                <input ref="fileInput" type="file" class="hidden" @change="upload" />
            </section>
        </div>
        <template v-if="classic">
            <section class="mt-[10px] rounded-[4px] bg-white p-[20px]">
                <h2 class="text-[17px] font-semibold text-ink">Historial de acciones</h2>
                <div v-for="h in d.history" :key="h.at + h.to" class="mt-[16px] flex rounded-[4px] border border-line text-[12px]">
                    <span class="w-[130px] border-r border-line px-[16px] py-[14px] text-ink-soft">{{ h.at }}</span>
                    <span class="px-[16px] py-[14px] font-semibold text-ink">{{ SHORT[d.type] }} {{ d.name }} <span class="text-[#1a237e]">enviada</span> a {{ h.name }} ({{ h.to }})</span>
                </div>
                <p v-if="!d.history?.length" class="mt-[16px] text-[12px] text-ink-soft">No hay acciones registradas.</p>
            </section>
            <div class="mt-[10px] grid grid-cols-[1fr_1fr] gap-[10px]">
                <section class="rounded-[4px] bg-white p-[20px]">
                    <h2 class="text-[17px] font-semibold text-ink">Información General</h2>
                    <table class="mt-[16px] w-full border border-line text-[12px]">
                        <tr><th class="w-[130px] p-[14px] text-left font-semibold">Cliente</th><td class="p-[14px]"><Link v-if="d.contact_id" :href="`/terceros/${d.contact_id}/editar`" class="font-semibold text-primary underline">{{ d.contact?.name }}</Link></td></tr>
                        <tr class="bg-[#f5f5f5]"><th class="p-[14px] text-left font-semibold">Deuda Total cliente</th><td class="p-[14px]"><Link :href="`/reportes/cartera-detallada?tercero=${d.contact_id}`" class="font-semibold text-primary underline">{{ money(d.debt) }}</Link></td></tr>
                        <tr><th class="p-[14px] text-left font-semibold">Comprobantes relacionados</th><td class="p-[14px]"><Link v-if="d.related_url" :href="d.related_url" class="font-semibold text-primary underline">{{ d.related }}</Link></td></tr>
                    </table>
                </section>
                <section class="rounded-[4px] bg-white p-[20px]">
                    <h2 class="text-[17px] font-semibold text-ink">Anexos</h2>
                    <a v-for="a in d.attachments" :key="a.id" :href="a.url" class="mt-[10px] block text-[12px] text-primary hover:underline"><Icon icon="paperclip" /> {{ a.name }}</a>
                    <button type="button" class="mt-[30px] px-[30px] text-[12px] text-ink" @click="fileInput.click()">Adjuntar archivo <Icon icon="paperclip" class="ml-[6px]" /></button>
                    <input ref="fileInput" type="file" class="hidden" @change="upload" />
                </section>
            </div>
        </template>

        <section v-if="showLedger && d.show_ledger !== false" class="s-card mt-[14px]">
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
            <button v-if="d.printable" type="button" class="s-btn s-btn-outline" @click="printInPlace(`/documentos/${d.kind}/${d.id}/imprimir`)"><Icon icon="print" /> Imprimir / PDF</button>
            <a v-if="d.dian?.xml" :href="`/documentos/${d.kind}/${d.id}/xml`" class="s-btn s-btn-outline"><Icon icon="file-code" /> XML</a>
            <button v-if="d.mailable && !annulled" type="button" class="s-btn s-btn-outline" @click="sendMail"><Icon icon="envelope" /> {{ ['sent', 'read', 'failed', 'failed_delivery'].includes(d.mail_status) ? 'Reenviar correo' : 'Enviar por correo' }}</button>
            <button v-if="d.favorite !== null" type="button" class="s-btn s-btn-outline" :title="d.favorite ? 'Quitar de favoritos' : 'Marcar como favorito para copiarlo después'" @click="router.post(`/documentos/${d.kind}/${d.id}/favorito`, {}, { preserveScroll: true, preserveState: false })">
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

    <section v-if="d.show_ledger !== false" class="s-card mt-[14px]">
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
            <thead><tr><th>Fecha</th><th>Producto</th><th>Bodega</th><th>Concepto</th><th class="text-right">Cantidad</th><th v-if="d.show_costs" class="text-right">Costo unitario</th><th class="text-right">Saldo</th></tr></thead>
            <tbody><tr v-for="(m, k) in d.movements" :key="k"><td>{{ m.date }}</td><td>{{ m.product }}</td><td>{{ m.warehouse }}</td><td>{{ m.concept }}</td><td class="text-right">{{ qty(m.quantity) }}</td><td v-if="d.show_costs" class="text-right">{{ money(m.unit_cost) }}</td><td class="text-right">{{ qty(m.balance) }}</td></tr></tbody>
        </table>
    </section>
    </template>
</template>

<style scoped>
.icon-btn { display: inline-flex; height: 34px; width: 34px; align-items: center; justify-content: center; border-radius: 9999px; border: 1px solid #d5dbe3; background: #fff; color: #0b4f8a; font-size: 14px; }
.icon-btn:hover { background: #f0f9ff; }
</style>
