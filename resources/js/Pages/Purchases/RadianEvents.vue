<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';

/* Compras > Registro de eventos (RADIAN), como la pantalla de Siigo: listado, filtros y panel de 3 pasos. */
const props = defineProps({
    events: { type: Object, required: true },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    idTypes: { type: Object, required: true },
    claims: { type: Object, required: true },
    remembered: { type: Object, default: null },
});

const SUBTABS = ['Eventos en proceso', 'Eventos finalizados'];
const STEPS = ['Recepción de factura', 'Recepción de mercancía / servicio', 'Aceptación de factura'];
const STATUSES = ['Pendiente', 'Validado', 'Rechazado'];
const RES = '(Res. DIAN 00085, art. 34 del 08 de abril de 2022 - Decreto 1074 de 2015).';

const f = reactive({ q: '', sub: SUBTABS[0], desde: '', hasta: '', proveedor: '', recepcion: '', mercancia: '', aceptacion: '', ...props.filters });
const finished = computed(() => f.sub === SUBTABS[1]);
const list = computed(() => props.events.data);

function reload(extra = {}) {
    Object.assign(f, extra);
    const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v));
    if (params.sub === SUBTABS[0]) delete params.sub;
    router.get('/compras/registro-eventos', params, { preserveState: true, replace: true });
}

const showFilters = ref(false);
const draft = reactive({});
function openFilters() {
    Object.assign(draft, { desde: f.desde, hasta: f.hasta, proveedor: f.proveedor, recepcion: f.recepcion, mercancia: f.mercancia, aceptacion: f.aceptacion });
    showFilters.value = true;
}
function applyFilters(clear = false) {
    if (clear) Object.keys(draft).forEach((k) => (draft[k] = ''));
    showFilters.value = false;
    reload({ ...draft });
}

const statusIcon = (s) => ({ Validado: ['circle-check', 'text-[#2e7d32]'], Aceptado: ['circle-check', 'text-[#2e7d32]'], Rechazado: ['circle-xmark', 'text-[#c62828]'], Reclamado: ['circle-xmark', 'text-[#c62828]'] })[s] ?? ['circle-exclamation', 'text-[#f29100]'];

const blankPerson = () => ({ first_name: '', last_name: '', id_type: '', id_number: '', job: '', department: '', remember: false });
const panel = ref(null);
const form = useForm({ cufe: '', ...blankPerson(), claim_code: '' });
const claiming = ref(false);

function personFrom(r) {
    const p = r ?? (props.remembered?.remember ? props.remembered : null);
    return p ? { ...blankPerson(), ...p, remember: !!p.remember } : blankPerson();
}
function openNew() {
    form.clearErrors();
    Object.assign(form, { cufe: '', claim_code: '', ...personFrom(null) });
    claiming.value = false;
    panel.value = { row: null, step: 0 };
}
function openRow(row) {
    form.clearErrors();
    claiming.value = false;
    const step = row.reception !== 'Validado' ? 0 : row.goods !== 'Validado' ? 1 : 2;
    Object.assign(form, { cufe: row.cufe, claim_code: '', ...personFrom(step === 1 && !row.receiver?.remember ? null : row.receiver) });
    panel.value = { row, step: finished.value ? 3 : step };
}
const close = () => (panel.value = null);
const row = computed(() => panel.value?.row);

const alert = computed(() => {
    const r = row.value;
    if (!r || panel.value.step === 3) return null;
    if (r.message && [r.reception, r.goods, r.acceptance].includes('Rechazado')) return { kind: 'danger', text: r.message };
    if (panel.value.step === 1) return { kind: 'warn', text: `Falta confirmar la recepción de la mercancía o la prestación del servicio para que los costos, deducciones e impuestos descontables de la factura electrónica de venta de tu proveedor con operaciones a crédito puedan servir de soporte. ${RES}` };
    if (panel.value.step === 2) return { kind: 'warn', text: `Falta aceptar o reclamar la factura electrónica de venta de tu proveedor. Si no lo haces dentro de los tres (3) días hábiles siguientes a la recepción de la mercancía o servicio, la factura queda aceptada tácitamente. ${RES}` };
    return null;
});

const done = { preserveScroll: true, onSuccess: () => { if (!Object.keys(form.errors).length) close(); } };
function confirmReception() {
    form.transform((d) => ({ ...d, remember: d.remember ? 1 : 0 })).post('/compras/registro-eventos', done);
}
function confirmGoods() {
    form.transform((d) => ({ ...d, remember: d.remember ? 1 : 0 })).post(`/compras/registro-eventos/${row.value.id}/mercancia`, done);
}
function accept() {
    if (!window.confirm('Se enviará a la DIAN la aceptación expresa de la factura. ¿Continuar?')) return;
    form.post(`/compras/registro-eventos/${row.value.id}/aceptar`, done);
}
function claim() {
    if (!claiming.value) return (claiming.value = true);
    form.post(`/compras/registro-eventos/${row.value.id}/reclamo`, done);
}
function remove() {
    if (!window.confirm('¿Deseas borrar este registro?')) return;
    router.delete(`/compras/registro-eventos/${row.value.id}`, { onSuccess: close });
}
</script>

<template>
    <Head title="Compras y gastos" />
    <PageHeader title="Compras y gastos" :tabs="tabs" active="Registro de eventos" />

    <h2 class="mt-[16px] text-[18px] font-bold text-ink">Registro de eventos en factura electrónica de proveedores</h2>
    <div class="mt-[12px] flex items-stretch overflow-hidden rounded-[4px] border border-primary bg-[#e8f4fd] text-[13px] leading-[22px] text-ink">
        <div class="grid w-[36px] shrink-0 place-items-center bg-primary text-white"><Icon icon="circle-info" /></div>
        <p class="px-[22px] py-[8px]">
            Para que las facturas electrónicas de venta emitidas por tus proveedores en operaciones a crédito, puedan servir como soporte de costos, deducciones y/o impuestos descontables,
            debes registrar el CUFE correspondiente para enviar a la DIAN los eventos de <b>Recepción de factura, Recepción de mercancía o servicio, y Aceptación o Reclamo de la factura</b>
            en los plazos establecidos (Res. DIAN 00085, art. 34 del 08 de abril de 2022 - Decreto 1074 de 2015). <a href="/ayuda#radian" target="_blank" class="font-semibold text-primary">Aprende más.</a>
        </p>
    </div>

    <section class="s-card mt-[44px]">
        <div class="flex gap-6 border-b border-line px-[16px]">
            <button v-for="s in SUBTABS" :key="s" type="button" class="-mb-px border-b-[3px] py-3 text-[14px] font-semibold"
                :class="f.sub === s ? 'border-primary text-primary' : 'border-transparent text-ink-soft'" @click="reload({ sub: s })">{{ s }}</button>
        </div>

        <form class="flex flex-wrap items-center gap-3 px-[16px] py-[16px]" @submit.prevent="reload()">
            <span class="flex">
                <input v-model="f.q" class="s-input w-[260px] rounded-r-none" placeholder="Buscar por CUFE" />
                <button type="submit" class="s-btn s-btn-primary rounded-l-none px-3" title="Buscar"><Icon icon="magnifying-glass" /></button>
            </span>
            <button type="button" class="s-btn s-btn-outline" @click="openFilters"><Icon icon="filter" /> Filtros</button>
            <button v-if="!finished" type="button" class="s-btn s-btn-primary ml-auto" @click="openNew">Registrar nueva factura</button>
        </form>

        <div class="overflow-x-auto">
            <table class="s-table w-full table-fixed [&_th]:truncate">
                <thead>
                    <tr v-if="finished">
                        <th>Fecha de registro</th><th>CUFE</th><th>NIT</th><th>Comprobante</th><th>Proveedor</th><th>Estado final de registro</th><th>Acciones</th>
                    </tr>
                    <tr v-else>
                        <th>Fecha de registro</th><th>CUFE</th><th>NIT</th><th>Comprobante</th><th>Proveedor</th><th>Recepción de factura</th>
                        <th>Recepción de mercancía o servicio</th><th>Aceptación o reclamo de la factura</th><th>Acciones</th><th>Id</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in list" :key="r.id">
                        <td class="whitespace-nowrap">{{ r.date }}</td>
                        <td class="truncate" :title="r.cufe">{{ r.cufe }}</td>
                        <td>{{ r.nit }}</td>
                        <td><a v-if="r.purchase_url" :href="r.purchase_url" class="font-semibold text-primary hover:underline">{{ r.number }}</a><template v-else>{{ r.number }}</template></td>
                        <td class="truncate" :title="r.supplier">{{ r.supplier }}</td>
                        <template v-if="finished">
                            <td class="whitespace-nowrap"><Icon :icon="statusIcon(r.final)[0]" :class="statusIcon(r.final)[1]" /> {{ r.final }}</td>
                            <td><button type="button" class="s-btn s-btn-outline h-[30px] px-3" @click="openRow(r)">Ver detalle</button></td>
                        </template>
                        <template v-else>
                            <td v-for="(s, k) in [r.reception, r.goods, r.acceptance]" :key="k" class="truncate">
                                <Icon :icon="statusIcon(s)[0]" :class="statusIcon(s)[1]" /> {{ s }}
                            </td>
                            <td><button type="button" class="s-btn s-btn-outline h-[30px] w-full px-2" @click="openRow(r)">{{ r.reception === 'Rechazado' ? 'Revisar' : 'Continuar' }}</button></td>
                            <td class="truncate text-muted" :title="r.ref">{{ r.ref }}</td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState v-if="!list.length" title="Aún no tienes facturas agregadas" text="Debes agregar facturas de tu proveedor para poder gestionar los eventos" />
        <Pager :paginator="events" />
    </section>

    <!-- Filtros -->
    <div v-if="showFilters" class="fixed inset-0 z-50 bg-black/40" @click.self="showFilters = false">
        <aside class="absolute bottom-0 right-0 top-0 flex w-[420px] max-w-full flex-col gap-4 bg-white p-6 text-[13px]">
            <div class="flex items-center justify-between">
                <h3 class="text-[16px] font-semibold text-ink">Define los filtros que deseas aplicar a la tabla</h3>
                <button type="button" class="text-primary" @click="showFilters = false"><Icon icon="xmark" /></button>
            </div>
            <div class="flex items-center gap-2">
                <label class="flex-1 text-[12px] text-ink-soft">Fecha inicial<input v-model="draft.desde" type="date" class="s-input" /></label>
                <span class="mt-4">a</span>
                <label class="flex-1 text-[12px] text-ink-soft">Fecha final<input v-model="draft.hasta" type="date" class="s-input" /></label>
            </div>
            <label class="text-[12px] text-ink-soft">Proveedor<input v-model="draft.proveedor" class="s-input" /></label>
            <label class="text-[12px] text-ink-soft">Estado de recepción de la factura
                <select v-model="draft.recepcion" class="s-input"><option value="" /><option v-for="s in STATUSES" :key="s">{{ s }}</option></select>
            </label>
            <label class="text-[12px] text-ink-soft">Estado de recepción de la mercancía
                <select v-model="draft.mercancia" class="s-input"><option value="" /><option v-for="s in STATUSES" :key="s">{{ s }}</option></select>
            </label>
            <label class="text-[12px] text-ink-soft">Estado de aceptación o reclamo de la factura
                <select v-model="draft.aceptacion" class="s-input"><option value="" /><option v-for="s in ['Pendiente', 'Aceptado', 'Reclamado', 'Rechazado']" :key="s">{{ s }}</option></select>
            </label>
            <div class="mt-auto flex justify-end gap-3">
                <button type="button" class="s-btn s-btn-outline" @click="applyFilters(true)">Limpiar filtros</button>
                <button type="button" class="s-btn s-btn-primary" @click="applyFilters()">Aplicar filtros</button>
            </div>
        </aside>
    </div>

    <!-- Panel de registro de eventos -->
    <div v-if="panel" class="fixed inset-0 z-50 bg-black/40" @click.self="close">
        <aside class="absolute bottom-0 right-0 top-0 flex w-[600px] max-w-full flex-col overflow-y-auto bg-white px-6 py-5 text-[13px]">
            <div class="flex items-center justify-between">
                <h3 class="text-[18px] font-semibold text-ink">Nuevo registro de evento en factura de proveedor</h3>
                <button type="button" class="text-primary" @click="close"><Icon icon="xmark" /></button>
            </div>

            <ol class="relative mt-6 flex justify-between">
                <span class="absolute left-[14px] right-[14px] top-[12px] h-[2px] bg-[#cfd8dc]" />
                <li v-for="(s, i) in STEPS" :key="s" class="relative z-10 flex flex-col items-center gap-2" :class="i === 0 ? 'items-start' : i === 2 ? 'items-end' : ''">
                    <span class="flex h-[26px] w-[26px] items-center justify-center rounded-full border-2 text-[12px] font-semibold"
                        :class="i < panel.step ? 'border-[#4caf50] bg-[#4caf50] text-white' : i === panel.step ? 'border-[#4caf50] bg-[#4caf50] text-white' : 'border-[#b0bec5] bg-white text-muted'">
                        <Icon v-if="i < panel.step" icon="check" /><template v-else>{{ i + 1 }}</template>
                    </span>
                    <span class="text-[12px] font-semibold" :class="i <= panel.step ? 'text-ink' : 'text-muted'">{{ s }}</span>
                </li>
            </ol>

            <div v-if="alert" class="mt-6 flex gap-3 rounded border px-4 py-3"
                :class="alert.kind === 'danger' ? 'border-[#c62828] bg-[#fdecee]' : 'border-[#f29100] bg-[#fff6e8]'">
                <Icon :icon="alert.kind === 'danger' ? 'circle-xmark' : 'triangle-exclamation'" :class="alert.kind === 'danger' ? 'text-[#c62828]' : 'text-[#f29100]'" class="mt-[2px]" />
                <p>{{ alert.text }}</p>
            </div>

            <template v-if="panel.step === 3">
                <h4 class="mt-6 font-semibold text-ink">Información de la factura</h4>
                <dl class="mt-2 grid grid-cols-[150px_1fr] gap-y-1">
                    <dt class="text-muted">CUFE</dt><dd class="break-all">{{ row.cufe }}</dd>
                    <dt class="text-muted">Proveedor</dt><dd>{{ row.nit }} {{ row.supplier }}</dd>
                    <dt class="text-muted">Comprobante</dt><dd>{{ row.number }}</dd>
                    <dt class="text-muted">Estado final</dt><dd>{{ row.final }}<template v-if="row.claim"> — {{ row.claim }}</template></dd>
                </dl>
                <h4 class="mt-6 font-semibold text-ink">Eventos enviados a la DIAN</h4>
                <table class="s-table mt-2 w-full">
                    <thead><tr><th>Evento</th><th>Número</th><th>Fecha</th><th>Estado</th><th /></tr></thead>
                    <tbody>
                        <tr v-for="e in row.events" :key="e.xml">
                            <td>{{ e.label }}</td><td>{{ e.number }}</td><td class="whitespace-nowrap">{{ e.at }}</td>
                            <td :title="e.message">{{ e.valid ? 'Validado' : 'Rechazado' }}</td>
                            <td><a :href="e.xml" class="font-semibold text-primary hover:underline">XML</a></td>
                        </tr>
                    </tbody>
                </table>
                <div class="mt-8 flex justify-center"><button type="button" class="font-semibold text-primary" @click="close">Cerrar</button></div>
            </template>

            <template v-else-if="panel.step === 2">
                <template v-if="claiming">
                    <h4 class="mt-6 font-semibold text-ink">Reclamo de la factura</h4>
                    <label class="mt-3 text-[12px] text-ink-soft">Motivo del reclamo <span class="text-[#f29100]">*</span>
                        <select v-model="form.claim_code" class="s-input"><option value="" /><option v-for="(label, code) in claims" :key="code" :value="code">{{ label }}</option></select>
                    </label>
                    <p v-if="form.errors.claim_code" class="mt-1 text-[12px] text-[#c62828]">{{ form.errors.claim_code }}</p>
                </template>
                <hr class="mt-6 border-line" />
                <div class="mt-6 flex flex-col items-center gap-4">
                    <button type="button" class="font-semibold text-primary" @click="claiming ? (claiming = false) : close()">Cancelar</button>
                    <div class="flex gap-3">
                        <button type="button" class="s-btn s-btn-outline" :disabled="form.processing" @click="claim"><Icon icon="xmark" /> {{ claiming ? 'Enviar reclamo' : 'Hacer reclamo' }}</button>
                        <button v-if="!claiming" type="button" class="s-btn s-btn-primary" :disabled="form.processing" @click="accept"><Icon icon="check" /> Aceptar factura</button>
                    </div>
                </div>
            </template>

            <template v-else>
                <template v-if="panel.step === 0">
                    <h4 class="mt-6 font-semibold text-ink">Información de la factura</h4>
                    <div class="mt-3 flex items-center gap-2">
                        <label class="flex-1 text-[12px] text-ink-soft">CUFE <span class="text-[#f29100]">*</span>
                            <input v-model="form.cufe" class="s-input" maxlength="96" :disabled="!!row" />
                        </label>
                        <Icon icon="circle-question" class="mt-4 text-primary" title="El CUFE es un código de 96 caracteres que se encuentra en la factura electrónica de venta" />
                    </div>
                    <p class="mt-1 text-[11px] text-muted">El CUFE es un código de 96 caracteres que se encuentra en la factura electrónica de venta</p>
                    <p v-if="form.errors.cufe" class="mt-1 text-[12px] text-[#c62828]">{{ form.errors.cufe }}</p>
                </template>

                <h4 class="mt-6 font-semibold text-ink">{{ panel.step === 0 ? 'Datos de quien recibió la factura' : 'Datos de quien recibió la mercancía / servicio' }}</h4>
                <p class="mt-2 text-ink-soft">{{ panel.step === 0 ? 'Ingresa los datos de la persona que recibió la factura de tu proveedor.' : 'Ingresa los datos de la persona que recibió la mercancía / servicio.' }}</p>
                <fieldset class="mt-4 grid grid-cols-2 gap-x-3 gap-y-4" :disabled="!!row && panel.step === 0">
                    <label class="text-[12px] text-ink-soft">Nombres <span class="text-[#f29100]">*</span><input v-model="form.first_name" class="s-input" />
                        <span v-if="form.errors.first_name" class="text-[#c62828]">{{ form.errors.first_name }}</span></label>
                    <label class="text-[12px] text-ink-soft">Apellidos <span class="text-[#f29100]">*</span><input v-model="form.last_name" class="s-input" />
                        <span v-if="form.errors.last_name" class="text-[#c62828]">{{ form.errors.last_name }}</span></label>
                    <label class="text-[12px] text-ink-soft">Tipo de identificación <span class="text-[#f29100]">*</span>
                        <select v-model="form.id_type" class="s-input" :class="!form.id_type && 'border-[#c62828]'"><option value="" /><option v-for="(label, code) in idTypes" :key="code" :value="code">{{ label }}</option></select>
                        <span v-if="!form.id_type || form.errors.id_type" class="text-[#c62828]">Campo requerido</span></label>
                    <label class="text-[12px] text-ink-soft">Número de identificación <span class="text-[#f29100]">*</span><input v-model="form.id_number" class="s-input" />
                        <span v-if="form.errors.id_number" class="text-[#c62828]">{{ form.errors.id_number }}</span></label>
                    <label class="text-[12px] text-ink-soft">Cargo<input v-model="form.job" class="s-input" /></label>
                    <label class="text-[12px] text-ink-soft">Área o departamento<input v-model="form.department" class="s-input" /></label>
                    <label class="col-span-2 flex items-center gap-2 text-ink"><input v-model="form.remember" type="checkbox" /> Usar los datos para el próximo evento</label>
                </fieldset>
                <hr class="mt-4 border-line" />
                <div class="mt-6 flex flex-col items-center gap-6">
                    <button type="button" class="font-semibold text-primary" @click="close">Cancelar</button>
                    <button v-if="row && panel.step === 0" type="button" class="s-btn s-btn-outline" @click="remove"><Icon icon="xmark" /> Borrar registro</button>
                    <button v-else-if="panel.step === 0" type="button" class="s-btn s-btn-primary" :disabled="form.processing" @click="confirmReception"><Icon icon="check" /> Confirmar recepción de factura</button>
                    <button v-else type="button" class="s-btn s-btn-primary" :disabled="form.processing" @click="confirmGoods"><Icon icon="check" /> Confirmar recepción de mercancía / servicio</button>
                </div>
            </template>
        </aside>
    </div>
</template>
