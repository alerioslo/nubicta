<script setup>
import { printInPlace } from '../../composables/print';
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { confirmDialog } from '../../composables/dialog';
import JournalSheet from '../../Components/JournalSheet.vue';

/* Comprobante contable: titulo, "Descargar e imprimir" y "Más" centrados, la hoja del comprobante y los adjuntos. */
const props = defineProps({ doc: { type: Object, required: true }, company: Object });
const d = props.doc;
const TITLES = { inventory_traslado: 'Nota de traslado entre bodegas', inventory_ensamble: 'Nota de ensamble', money_transfer: 'Traslado de dinero', portfolio_adjustment: 'Ajuste de cartera / proveedores' };
const title = TITLES[d.source_type] ?? 'Comprobante contable';
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const qty = (v) => Number(v || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
const annulled = d.status === 'annulled';
const menu = ref(false);
const showLedger = ref(false);
const ledgerTotal = (k) => d.ledger.reduce((a, l) => a + l[k], 0);
const fileInput = ref(null);
const page = usePage();
const error = computed(() => page.props.errors?.items);

async function annul() {
    menu.value = false;
    if (await confirmDialog(`¿Anular el documento ${d.name}? Se reversará su contabilización, inventario y saldos.`)) router.post(`/documentos/${d.kind}/${d.id}/anular`, {}, { preserveScroll: true, preserveState: false });
}
async function remove() {
    menu.value = false;
    const question = annulled ? '¿Estás seguro que deseas eliminar este comprobante contable?' : '¿Estás seguro que deseas borrar este comprobante contable?';
    if (await confirmDialog(question)) router.post(`/documentos/${d.kind}/${d.id}/eliminar`);
}
function favorite() {
    menu.value = false;
    router.post(`/documentos/${d.kind}/${d.id}/favorito`, {}, { preserveScroll: true, preserveState: false });
}
function upload(e) {
    const file = e.target.files?.[0];
    if (file) router.post(`/documentos/${d.kind}/${d.id}/adjuntos`, { attachment: file }, { forceFormData: true, preserveScroll: true, preserveState: false });
}
</script>

<template>
    <Head :title="d.name" />
    <div class="pb-[30px]" @click="menu = false">
        <h1 class="text-[20px] text-primary">{{ title }}: {{ d.name }}</h1>
        <p class="mt-[4px] text-[11px] text-ink-soft">Creado: {{ [d.created?.by, d.created?.at].filter(Boolean).join(' ') }} hrs.</p>

        <div class="mt-[8px] flex justify-center gap-[4px]" @click.stop>
            <button type="button" class="doc-btn doc-btn-green inline-flex h-[26px] items-center px-[10px]" @click="printInPlace(`/documentos/${d.kind}/${d.id}/imprimir`)">Descargar e imprimir</button>
            <div class="relative flex">
                <button type="button" class="doc-btn doc-btn-blue inline-flex h-[26px] items-center px-[10px]" @click="menu = !menu">Más <Icon icon="chevron-down" class="ml-[10px]" /></button>
                <div v-if="menu" class="absolute left-0 top-[30px] z-20 min-w-[170px] rounded-[2px] border border-line bg-white py-[4px] text-[12px] shadow-lg">
                    <template v-if="!annulled">
                        <Link v-if="d.editable && !d.blocker" :href="`/documentos/${d.kind}/${d.id}/editar`" class="item">Editar</Link>
                        <span v-else class="item off" :title="d.blocker || 'Este comprobante no se puede editar'">Editar</span>
                        <button type="button" class="item" :class="{ off: d.blocker }" :disabled="!!d.blocker" :title="d.blocker" @click="annul">Anular</button>
                        <button type="button" class="item" :class="{ off: d.blocker }" :disabled="!!d.blocker" :title="d.blocker" @click="remove">Borrar</button>
                        <button v-if="d.show_ledger !== false" type="button" class="item" @click="showLedger = !showLedger; menu = false">{{ showLedger ? 'Ocultar contabilización' : 'Ver contabilización' }}</button>
                        <Link v-if="d.copy_url" :href="d.copy_url" class="item">Copiar</Link>
                        <button v-if="d.favorite !== null" type="button" class="item" @click="favorite"><Icon v-if="d.favorite" icon="check" class="mr-[6px] text-[#43a047]" />Comprobante favorito</button>
                    </template>
                    <template v-else>
                        <button type="button" class="item" :class="{ off: d.source !== 'erp' }" :disabled="d.source !== 'erp'" :title="d.source !== 'erp' ? 'Los documentos importados de Siigo son de solo consulta.' : ''" @click="remove">Eliminar</button>
                        <Link v-if="d.copy_url" :href="d.copy_url" class="item">Copiar</Link>
                    </template>
                </div>
            </div>
        </div>

        <div v-if="annulled" class="mt-[10px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">Documento anulado<span v-if="d.annulled_at"> el {{ d.annulled_at }}</span>.</div>
        <div v-else-if="d.blocker && d.source === 'erp'" class="mt-[10px] rounded-[2px] border border-[#ffcc80] bg-[#fff8e1] px-4 py-2 text-[12px] text-[#8d6e00]">{{ d.blocker }}</div>
        <div v-if="error" class="mt-[10px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">{{ error }}</div>

        <div class="mt-[14px] border-y border-[#ddd] bg-white py-[10px]"><JournalSheet :doc="d" :company="company" /></div>

        <section v-if="showLedger && d.show_ledger !== false" class="s-card mt-[14px]">
            <h2 class="px-4 pt-3 text-[13px] font-bold">Contabilización</h2>
            <p v-if="!d.ledger.length" class="p-4 text-[12px] text-muted">Este comprobante no tiene movimientos en el libro auxiliar.</p>
            <table v-else class="s-table">
                <thead><tr><th>Cuenta</th><th>Tercero</th><th>Descripción</th><th class="text-right">Débito</th><th class="text-right">Crédito</th></tr></thead>
                <tbody>
                    <tr v-for="(l, k) in d.ledger" :key="k"><td>{{ l.account }}</td><td>{{ l.contact }}</td><td>{{ l.description }}</td><td class="text-right">{{ l.debit ? money(l.debit) : '' }}</td><td class="text-right">{{ l.credit ? money(l.credit) : '' }}</td></tr>
                    <tr class="font-bold"><td colspan="3" class="text-right">Totales</td><td class="text-right">{{ money(ledgerTotal('debit')) }}</td><td class="text-right">{{ money(ledgerTotal('credit')) }}</td></tr>
                </tbody>
            </table>
        </section>

        <section v-if="d.movements?.length" class="s-card mt-[14px]">
            <h2 class="px-4 pt-3 text-[13px] font-bold">Movimientos de inventario</h2>
            <table class="s-table">
                <thead><tr><th>Fecha</th><th>Producto</th><th>Bodega</th><th>Concepto</th><th class="text-right">Cantidad</th><th v-if="d.show_costs" class="text-right">Costo unitario</th><th class="text-right">Saldo</th></tr></thead>
                <tbody><tr v-for="(m, k) in d.movements" :key="k"><td>{{ m.date }}</td><td>{{ m.product }}</td><td>{{ m.warehouse }}</td><td>{{ m.concept }}</td><td class="text-right">{{ qty(m.quantity) }}</td><td v-if="d.show_costs" class="text-right">{{ money(m.unit_cost) }}</td><td class="text-right">{{ qty(m.balance) }}</td></tr></tbody>
            </table>
        </section>

        <div class="mt-[10px] px-[6px] text-[11px] text-ink">
            <a v-for="a in d.attachments" :key="a.id" :href="a.url" class="mb-[6px] block text-primary hover:underline"><Icon icon="paperclip" /> {{ a.name }}</a>
            <button type="button" @click="fileInput.click()">Adjuntar archivo <Icon icon="paperclip" class="ml-[6px]" /></button>
            <input ref="fileInput" type="file" class="hidden" @change="upload" />
        </div>
    </div>
</template>

<style scoped>
.item { display: block; width: 100%; padding: 6px 14px; text-align: left; }
.item:hover { background: #f0f9ff; }
.item.off { color: #bdbdbd; cursor: not-allowed; }
.item.off:hover { background: transparent; }
</style>
