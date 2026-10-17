<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { confirmDialog } from '../../composables/dialog';
import PageHeader from '../../Components/PageHeader.vue';
import AccountPicker from '../../Components/AccountPicker.vue';

/* Conciliacion bancaria con extracto Bancolombia: importar, cruzar automatico/manual y contabilizar partidas del banco. */
const props = defineProps({
    tabs: Array, banks: Array, bank: Number, until: String, summary: Object, matched: Array, statements: Array, concepts: Array,
});
const page = usePage();
const errors = computed(() => page.props.errors ?? {});
const money = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
const filters = reactive({ cuenta: props.bank, corte: props.until });
const upload = useForm({ bank_account_id: props.bank, file: null, year: Number((props.until ?? '').slice(0, 4)) || new Date().getFullYear() });
const picked = reactive({ bank: null, book: null });
const concept = reactive(Object.fromEntries((props.summary?.bank_only ?? []).map((l) => [l.id, l.suggest])));
const accountFor = reactive({});
const accountLabel = reactive({});
const busy = ref(false);
const s = props.summary;

const bankPick = computed(() => s?.bank_only.find((l) => l.id === picked.bank));
const bookPick = computed(() => s?.book_only.find((l) => l.id === picked.book));
const canMatch = computed(() => bankPick.value && bookPick.value && Math.abs(bankPick.value.amount - bookPick.value.amount) < 0.01);
const sameAmount = (l) => bankPick.value && Math.abs(bankPick.value.amount - l.amount) < 0.01;
const TYPES = { auto: 'Automático', manual: 'Manual', created: 'Contabilizado' };

const consult = () => router.get('/bancos/conciliacion', { cuenta: filters.cuenta, corte: filters.corte }, { preserveState: false });
const opts = { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) };
const auto = () => router.post('/bancos/conciliacion/auto', { bank_account_id: props.bank }, opts);
const match = () => router.post('/bancos/conciliacion/cruzar', { line_id: picked.bank, ledger_line_id: picked.book }, opts);
const unmatch = (id) => router.post('/bancos/conciliacion/descruzar', { line_id: id }, opts);
const post = (l) => router.post('/bancos/conciliacion/contabilizar', { line_id: l.id, concept: concept[l.id], account_id: accountFor[l.id] ?? null }, opts);
const removeStatement = async (st) => (await confirmDialog(`¿Eliminar la importación ${st.file_name}?`)) && router.delete(`/bancos/conciliacion/extracto/${st.id}`, opts);
const submitUpload = () => upload.post('/bancos/conciliacion/importar', { forceFormData: true, preserveScroll: true, onSuccess: () => upload.reset('file') });
</script>

<template>
    <Head title="Conciliación bancaria" />
    <PageHeader title="Cajas y bancos" :tabs="tabs" active="Conciliación bancaria" />
    <h2 class="-mx-3 md:-mx-[22px] border-b border-line bg-white px-[22px] py-[18px] text-[22px] font-semibold text-ink">Conciliación bancaria</h2>

    <section class="s-card mt-[18px] flex flex-wrap items-end gap-4 p-4 text-[12px]">
        <label class="flex flex-col gap-1">Cuenta bancaria
            <select v-model="filters.cuenta" class="s-input h-[32px] min-w-[300px]">
                <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.account }} - {{ b.name }}</option>
            </select>
        </label>
        <label class="flex flex-col gap-1">Fecha de corte <input v-model="filters.corte" type="date" class="s-input h-[32px]" /></label>
        <button type="button" class="s-btn s-btn-primary" @click="consult">Consultar</button>
        <button type="button" class="s-btn s-btn-outline" :disabled="busy || !summary?.from" @click="auto"><Icon icon="link" /> Conciliar automáticamente</button>
    </section>

    <section class="s-card mt-[14px] p-4 text-[12px]">
        <h3 class="text-[13px] font-bold">Importar extracto Bancolombia</h3>
        <p class="mt-1 text-muted">Descarga los movimientos desde la Sucursal Virtual (Personas o Empresas) en Excel, CSV o TXT. Se reconocen las columnas FECHA, DESCRIPCIÓN, SUCURSAL/CANAL, REFERENCIA, DCTO., VALOR (o DÉBITO y CRÉDITO) y SALDO, y también el archivo plano sin encabezado. Los movimientos ya importados no se duplican.</p>
        <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="submitUpload">
            <input type="file" accept=".xlsx,.xls,.csv,.txt,.ods,.prn" class="text-[12px]" @change="upload.file = $event.target.files[0]" />
            <label class="flex flex-col gap-1">Año (si el extracto trae fechas dd/mm) <input v-model="upload.year" type="number" class="s-input h-[32px] w-[110px]" /></label>
            <button type="submit" class="s-btn s-btn-primary" :disabled="upload.processing || !upload.file"><Icon icon="file-import" /> Importar</button>
            <span v-if="upload.errors.file || errors.file" class="text-[#c62828]">{{ upload.errors.file || errors.file }}</span>
        </form>
    </section>

    <div v-if="errors.line" class="mt-[14px] rounded-[2px] border border-[#e57373] bg-[#fdecea] px-4 py-2 text-[12px] text-[#c62828]">{{ errors.line }}</div>

    <section v-if="s" class="s-card mt-[14px] p-4 text-[12.5px]">
        <h3 class="mb-2 text-[13px] font-bold">Resumen al {{ until }}<span v-if="s.from" class="font-normal text-muted"> · extractos desde {{ s.from }}</span></h3>
        <div class="s-table-wrap">
            <table class="w-full max-w-[560px]">
                <tbody>
                    <tr><td>Saldo en libros (auxiliar)</td><td class="text-right">{{ money(s.book) }}</td></tr>
                    <tr><td>(+) Movimientos del banco no registrados en libros</td><td class="text-right">{{ money(s.bank_only_sum) }}</td></tr>
                    <tr><td>(−) Movimientos en libros no reflejados en el extracto</td><td class="text-right">{{ money(s.book_only_sum) }}</td></tr>
                    <tr class="font-bold"><td class="border-t pt-1">Saldo conciliado</td><td class="border-t pt-1 text-right">{{ money(s.reconciled) }}</td></tr>
                    <tr><td>Saldo según extracto</td><td class="text-right">{{ money(s.statement) }}</td></tr>
                    <tr class="font-bold" :class="s.difference === 0 ? 'text-[#2e7d32]' : 'text-[#c62828]'">
                        <td>Diferencia</td><td class="text-right">{{ s.difference === null ? 'El extracto no trae saldo' : money(s.difference) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <div v-if="s" class="mt-[14px] grid gap-[14px] xl:grid-cols-2">
        <section class="s-card">
            <div class="flex items-center justify-between px-4 pt-3">
                <h3 class="text-[13px] font-bold">En el extracto y no en libros ({{ s.bank_only.length }})</h3>
                <button type="button" class="s-btn s-btn-primary h-[28px]" :disabled="!canMatch || busy" @click="match"><Icon icon="link" /> Cruzar seleccionados</button>
            </div>
            <p class="px-4 text-[11px] text-muted">Selecciona una partida aquí y su registro contable a la derecha para cruzarlos, o contabilízala (4x1000, cuota de manejo, intereses...).</p>
            <div class="max-h-[560px] overflow-y-auto">
                <div class="s-table-wrap">
                    <table class="s-table">
                        <thead><tr><th></th><th>Fecha</th><th>Descripción</th><th class="text-right">Valor</th><th>Contabilizar como</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="l in s.bank_only" :key="l.id" :class="picked.bank === l.id && 'bg-[#e3f1fb]'">
                                <td><input v-model="picked.bank" type="radio" :value="l.id" /></td>
                                <td class="whitespace-nowrap">{{ l.date }}</td>
                                <td class="max-w-[220px]"><div class="truncate" :title="l.description">{{ l.description }}</div><div class="text-[10.5px] text-muted">{{ l.reference }} {{ l.branch }}</div></td>
                                <td class="whitespace-nowrap text-right" :class="l.amount < 0 && 'text-[#c62828]'">{{ money(l.amount) }}</td>
                                <td>
                                    <select v-model="concept[l.id]" class="s-input h-[26px] w-[150px] text-[11px]">
                                        <option v-for="c in concepts" :key="c.key" :value="c.key">{{ c.label }}</option>
                                    </select>
                                    <div v-if="concept[l.id] === 'other'" class="mt-1 w-[180px] text-[11px]">
                                        <AccountPicker v-model="accountFor[l.id]" v-model:label="accountLabel[l.id]" />
                                    </div>
                                </td>
                                <td><button type="button" class="s-btn s-btn-outline h-[26px] px-2 text-[11px]" :disabled="busy" @click="post(l)">Contabilizar</button></td>
                            </tr>
                            <tr v-if="!s.bank_only.length"><td colspan="6" class="py-4 text-center text-muted">Todo el extracto está conciliado.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="s-card">
            <h3 class="px-4 pt-3 text-[13px] font-bold">En libros y no en el extracto ({{ s.book_only.length }})</h3>
            <p class="px-4 text-[11px] text-muted">Cheques o pagos aún no cobrados y consignaciones en tránsito. Se resaltan los que tienen el mismo valor de la partida seleccionada.</p>
            <div class="max-h-[560px] overflow-y-auto">
                <div class="s-table-wrap">
                    <table class="s-table">
                        <thead><tr><th></th><th>Fecha</th><th>Comprobante</th><th>Tercero</th><th class="text-right">Valor</th></tr></thead>
                        <tbody>
                            <tr v-for="l in s.book_only" :key="l.id" :class="[picked.book === l.id && 'bg-[#e3f1fb]', sameAmount(l) && picked.book !== l.id && 'bg-[#fff8e1]']">
                                <td><input v-model="picked.book" type="radio" :value="l.id" /></td>
                                <td class="whitespace-nowrap">{{ l.date }}</td>
                                <td class="whitespace-nowrap">{{ l.document }}</td>
                                <td class="max-w-[200px] truncate" :title="l.contact">{{ l.contact }}</td>
                                <td class="whitespace-nowrap text-right" :class="l.amount < 0 && 'text-[#c62828]'">{{ money(l.amount) }}</td>
                            </tr>
                            <tr v-if="!s.book_only.length"><td colspan="5" class="py-4 text-center text-muted">No hay registros pendientes.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <section v-if="matched.length" class="s-card mt-[14px]">
        <h3 class="px-4 pt-3 text-[13px] font-bold">Conciliados en el mes ({{ matched.length }})</h3>
        <div class="s-table-wrap">
            <table class="s-table">
                <thead><tr><th>Fecha extracto</th><th>Descripción</th><th class="text-right">Valor</th><th>Comprobante</th><th>Fecha libros</th><th>Tipo</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="m in matched" :key="m.id">
                        <td class="whitespace-nowrap">{{ m.date }}</td><td class="max-w-[260px] truncate">{{ m.description }}</td>
                        <td class="text-right">{{ money(m.amount) }}</td><td>{{ m.document }}</td><td>{{ m.book_date }}</td><td>{{ TYPES[m.match_type] ?? m.match_type }}</td>
                        <td><button type="button" class="s-btn-link text-[11px]" @click="unmatch(m.id)"><Icon icon="link-slash" /> Deshacer</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section v-if="statements.length" class="s-card mt-[14px]">
        <h3 class="px-4 pt-3 text-[13px] font-bold">Extractos importados</h3>
        <div class="s-table-wrap">
            <table class="s-table">
                <thead><tr><th>Archivo</th><th>Formato</th><th>Periodo</th><th class="text-right">Movimientos</th><th class="text-right">Saldo inicial</th><th class="text-right">Saldo final</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="st in statements" :key="st.id">
                        <td>{{ st.file_name }}</td><td>{{ st.format }}</td><td class="whitespace-nowrap">{{ st.period_from }} a {{ st.period_to }}</td>
                        <td class="text-right">{{ st.lines_count }}</td><td class="text-right">{{ money(st.opening_balance) }}</td><td class="text-right">{{ money(st.closing_balance) }}</td>
                        <td><button type="button" class="s-btn-link text-[11px] text-[#c62828]" @click="removeStatement(st)"><Icon icon="trash" /> Eliminar</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
