<script setup>
import { printInPlace } from '../../composables/print';
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { confirmDialog, promptDialog } from '../../composables/dialog';
import ReceiptSheet from '../../Components/ReceiptSheet.vue';
import { onClickOutside } from '../../composables/clickOutside';

/* Recibo de caja / de pago abierto: barra de acciones, "Creado:" y la hoja del recibo. */
const props = defineProps({ doc: { type: Object, required: true }, company: Object });
const d = props.doc;
const page = usePage();
const error = computed(() => page.props.errors?.items);
const back = d.type === 'RC' ? '/ventas/recaudos' : '/compras/pagos';
const base = `/documentos/${d.kind}/${d.id}`;
const annulled = d.status === 'annulled';
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const more = ref(false);
const moreRef = ref(null);
const ledgerOpen = ref(false);
onClickOutside(moreRef, () => (more.value = false));
const ledgerTotal = (k) => d.ledger.reduce((a, l) => a + l[k], 0);

async function annul() {
    more.value = false;
    if (await confirmDialog(`¿Anular el documento ${d.name}? Se reversará su contabilización y los saldos abonados.`)) router.post(`${base}/anular`, {}, { preserveScroll: true, preserveState: false });
}
async function remove() {
    more.value = false;
    if (await confirmDialog(`¿Eliminar el documento ${d.name}? Esta acción no se puede deshacer.`)) router.post(`${base}/eliminar`);
}
async function sendMail() {
    const to = await promptDialog('Correo del destinatario', d.contact?.email ?? '');
    if (to) router.post(`${base}/correo`, { to }, { preserveScroll: true, preserveState: false });
}
</script>

<template>
    <Head :title="d.name" />
    <div class="-mx-3 md:-mx-[22px] flex items-center justify-between bg-primary px-[16px] py-[14px] text-white">
        <h1 class="text-[15px] font-semibold">{{ d.type === 'RC' ? 'Recibo de caja' : 'Recibo de pago' }} : {{ d.name }}</h1>
        <Link :href="back" class="text-[15px]" title="Cerrar"><Icon icon="xmark" /></Link>
    </div>

    <div v-if="error" class="mt-[14px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">{{ error }}</div>

    <section class="s-card mt-[14px] pb-[30px]">
        <div class="flex items-center justify-between border-b border-line px-[16px] py-[14px]">
            <div class="flex items-center gap-[8px]">
                <button v-if="!annulled" type="button" class="s-btn s-btn-primary" @click="sendMail"><Icon icon="paper-plane" /> Enviar por email</button>
                <Link v-if="d.editable && !d.blocker" :href="`${base}/editar`" class="s-btn s-btn-outline"><Icon icon="pen" /> Editar</Link>
                <div ref="moreRef" class="relative">
                    <button type="button" class="s-btn s-btn-outline" @click="more = !more">Más <Icon icon="caret-down" /></button>
                    <div v-if="more" class="absolute left-0 top-[calc(100%+4px)] z-20 w-[170px] rounded-[4px] bg-white py-[6px] text-[13px] shadow-[0_2px_10px_#0003]">
                        <template v-if="!d.blocker">
                            <button type="button" class="block w-full px-[14px] py-[6px] text-left hover:bg-[#f4f5f7]" @click="annul">Anular</button>
                            <button type="button" class="block w-full px-[14px] py-[6px] text-left hover:bg-[#f4f5f7]" @click="remove">Eliminar</button>
                        </template>
                        <button type="button" class="block w-full px-[14px] py-[6px] text-left hover:bg-[#f4f5f7]" @click="ledgerOpen = true; more = false">Ver contabilización</button>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-[18px] text-[16px] text-primary">
                <button type="button" title="Imprimir" @click="printInPlace(`${base}/imprimir`)"><Icon icon="print" /></button>
            </div>
        </div>

        <p v-if="d.blocker && d.source !== 'erp'" class="mx-auto mt-[14px] w-[590px] text-[12px] text-muted">{{ d.blocker }}</p>
        <div class="mx-auto mt-[30px] w-[590px] rounded-[4px] bg-[#f4f5f7] px-[20px] py-[10px] text-[11px]">
            <b>Creado:</b>&nbsp; {{ d.created }}
            <span v-if="annulled" class="ml-[10px] text-[#c62828]"><b>Anulado:</b> {{ d.annulled_at }}</span>
            <p v-if="d.attachments?.length" class="mt-[4px]"><b>Archivos adjuntos:</b>
                <a v-for="a in d.attachments" :key="a.id" :href="a.url" class="ml-[8px] text-primary hover:underline"><Icon icon="paperclip" /> {{ a.name }}</a></p>
        </div>
        <div class="mt-[24px]">
            <ReceiptSheet :doc="d" :company="company" />
        </div>
    </section>

    <div v-if="ledgerOpen" class="fixed inset-0 z-50 flex items-start justify-center p-3 bg-black/40 pt-[60px]" @click.self="ledgerOpen = false">
        <div class="w-[900px] max-w-[95vw] rounded-[4px] bg-white">
            <div class="flex items-center justify-between border-b border-line px-[16px] py-[12px]">
                <h2 class="text-[15px] font-semibold">Contabilización {{ d.name }}</h2>
                <button type="button" @click="ledgerOpen = false"><Icon icon="xmark" /></button>
            </div>
            <p v-if="!d.ledger.length" class="p-4 text-[12px] text-muted">{{ annulled ? 'El documento anulado no tiene contabilización.' : 'Este documento no genera contabilización.' }}</p>
            <table v-else class="s-table">
                <thead><tr><th>Cuenta contable</th><th>Tercero</th><th class="text-right">Débito</th><th class="text-right">Crédito</th></tr></thead>
                <tbody>
                    <tr v-for="(l, k) in d.ledger" :key="k"><td>{{ l.account }}</td><td>{{ l.contact }}</td><td class="text-right">{{ l.debit ? money(l.debit) : '' }}</td><td class="text-right">{{ l.credit ? money(l.credit) : '' }}</td></tr>
                    <tr class="font-bold"><td colspan="2" class="text-right">Totales</td><td class="text-right">{{ money(ledgerTotal('debit')) }}</td><td class="text-right">{{ money(ledgerTotal('credit')) }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
