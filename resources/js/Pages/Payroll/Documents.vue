<script setup>
import DownloadMenu from '../../Components/DownloadMenu.vue';
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '../../composables/dialog';
import PageHeader from '../../Components/PageHeader.vue';
import EmptyState from '../../Components/EmptyState.vue';
import RowMenu from '../../Components/RowMenu.vue';

const props = defineProps({
    tab: { type: String, default: 'dian' },
    docs: { type: Array, required: true },
    periods: { type: Array, required: true },
    period: { type: String, default: null },
    total: { type: Number, default: 0 },
    tabs: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    mail: { type: Object, default: null },
    config: { type: Object, default: null },
});
const SUBTABS = [['dian', 'Envío a la DIAN'], ['correo', 'Envíos por correo'], ['configuracion', 'Configuración']];
const f = reactive({ q: '', periodo: props.period, ...props.filters });
const reload = () => router.get('/nomina/documentos', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
const MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const label = (p) => `${MONTHS[Number(p.slice(5, 7)) - 1]} de ${p.slice(0, 4)}`;
const money = (v) => Number(v).toLocaleString('es-CO', { maximumFractionDigits: 0 });
const query = (params) => new URLSearchParams(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '')).toString();

const selected = ref([]);
const sendable = computed(() => props.docs.filter((d) => d.sendable));
const allChecked = computed(() => sendable.value.length > 0 && selected.value.length === sendable.value.length);
const toggleAll = () => (selected.value = allChecked.value ? [] : sendable.value.map((d) => d.id));
const busy = ref(false);
const send = () => router.post('/nomina/documentos/enviar', { ids: selected.value }, {
    preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => { busy.value = false; selected.value = []; },
});

const generating = ref(null);
const generate = () => router.post('/nomina/documentos/generar', { periodo: generating.value }, { onSuccess: () => (generating.value = null) });

const menu = ref(null);
const adjusting = ref(null);
const adjustType = ref('1');
const adjust = () => router.post(`/nomina/documentos/${adjusting.value.id}/ajuste`, { tipo: adjustType.value }, { preserveScroll: true, onSuccess: () => (adjusting.value = null) });
async function remove(d) {
    menu.value = null;
    if (await confirmDialog(`¿Eliminar el documento ${d.consecutive}?`)) router.delete(`/nomina/documentos/${d.id}`, { preserveScroll: true });
}

/* Envios por correo */
const mf = reactive({ q: '', desde: '', hasta: '', tipo: '', estado: '', ...(props.mail?.filters ?? {}) });
const mailReload = (page = null) => router.get('/nomina/documentos', { tab: 'correo', ...Object.fromEntries(Object.entries(mf).filter(([, v]) => v)), ...(page ? { page } : {}) }, { preserveState: true, replace: true });
const showFilters = ref(false);
const clearFilters = () => { Object.assign(mf, { desde: '', hasta: '', tipo: '', estado: '' }); showFilters.value = false; mailReload(); };
const activeFilters = computed(() => ['desde', 'hasta', 'tipo', 'estado'].filter((k) => props.mail?.filters?.[k]).length);
const allMail = ref(true);
const mailSelected = ref([]);
const mailCount = computed(() => (allMail.value ? props.mail?.total ?? 0 : mailSelected.value.length));
const toggleAllMail = () => { allMail.value = !allMail.value; mailSelected.value = []; };
const mailing = ref(false);
const mailOptions = { preserveScroll: true, onStart: () => (mailing.value = true), onFinish: () => (mailing.value = false) };
const sendMail = () => router.post('/nomina/documentos/correo', allMail.value ? { all: true, ...Object.fromEntries(Object.entries(mf).filter(([, v]) => v)) } : { ids: mailSelected.value }, mailOptions);
const sendOne = (r) => router.post('/nomina/documentos/correo', { ids: [r.id] }, mailOptions);
const editing = ref(null);
const emailForm = useForm({ email: '', replace: false });
const editEmail = (r) => { editing.value = r; emailForm.email = r.email ?? ''; emailForm.replace = false; emailForm.clearErrors(); };
const saveEmail = () => emailForm.put(`/nomina/documentos/${editing.value.id}/correo`, { preserveScroll: true, onSuccess: () => (editing.value = null) });
const statusIcon = (s) => (s === 'Enviado' ? ['circle-check', 'text-[#5a9e2b]'] : s === 'Envío fallido' ? ['circle-xmark', 'text-danger'] : ['circle-exclamation', 'text-[#f5a623]']);

/* Configuracion */
const cf = useForm({
    sum_provisions: !!props.config?.sum_provisions, prefix: props.config?.prefix ?? 'DNE', start: props.config?.start ?? 1,
    note_prefix: props.config?.note_prefix ?? 'DNA', note_start: props.config?.note_start ?? 1,
});
const saveConfig = () => cf.put('/nomina/documentos/configuracion', { preserveScroll: true });
const download = (base, f) => (window.location.href = base + (base.includes('?') ? '&' : '?') + 'formato=' + f);
</script>

<template>
    <Head title="Documentos electrónicos de nómina" />
    <PageHeader title="Nómina" :tabs="tabs" active="Documentos electrónicos" />

    <div class="mt-[16px] flex justify-end">
        <Link href="/configuracion/facturacion-electronica" class="s-btn s-btn-outline">Habilitación electrónica nómina</Link>
    </div>
    <h2 class="mt-2 text-[24px] font-semibold text-ink">Documentos electrónicos de nómina</h2>

    <section class="s-card mt-[10px]">
        <div class="s-subtabs bg-[#f5f6f7] px-[26px]">
            <Link v-for="[key, name] in SUBTABS" :key="key" :href="key === 'dian' ? '/nomina/documentos' : `/nomina/documentos?tab=${key}`" class="s-subtab" :class="{ active: tab === key }">{{ name }}</Link>
        </div>

        <template v-if="tab === 'dian'">
            <div class="flex flex-wrap items-end gap-4 p-[16px]">
                <DownloadMenu class="self-center" button-class="text-[18px] text-muted hover:text-primary" label="" title="Descargar" @download="(x) => download(`/nomina/documentos/excel?${query({ periodo: f.periodo })}`, x)" />
                <button type="button" class="s-btn s-btn-primary" :disabled="!selected.length || busy" @click="send">Enviar ({{ selected.length }})</button>
                <span class="text-[13px] font-semibold">Documentos disponibles: <span class="text-[#5a9e2b]">{{ total }}</span></span>
                <label>
                    <span class="s-label">Periodo de nómina <span class="req">*</span></span>
                    <select v-model="f.periodo" class="s-input w-[200px]" @change="reload">
                        <option v-for="p in periods" :key="p" :value="p">{{ label(p) }}</option>
                    </select>
                </label>
                <form class="flex" @submit.prevent="reload">
                    <input v-model="f.q" class="s-input w-[200px] rounded-r-none sm:w-[260px]" placeholder="Buscar por Nombre/identificación" />
                    <button type="submit" class="s-btn s-btn-primary rounded-l-none px-3"><Icon icon="magnifying-glass" /></button>
                </form>
                <button type="button" class="s-btn s-btn-outline ml-auto" @click="generating = f.periodo">Generar documentos</button>
            </div>
            <div class="s-table-wrap">
                <table class="sticky-actions w-full border-collapse">
                    <thead>
                        <tr class="bg-primary text-left text-[14px] font-semibold text-white">
                            <th class="w-[40px] px-3 py-[9px]"><input type="checkbox" :checked="allChecked" :disabled="!sendable.length" @change="toggleAll" /></th>
                            <th class="px-3">Fecha de generación</th>
                            <th class="px-3">Tipo</th>
                            <th class="px-3">Consecutivo</th>
                            <th class="px-3">Empleado</th>
                            <th class="px-3"># Identificación</th>
                            <th class="px-3">#Contrato</th>
                            <th class="px-3 text-right">Neto</th>
                            <th class="px-3">Estado</th>
                            <th class="px-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in docs" :key="d.id" class="border-b border-line">
                            <td class="px-3 py-[9px]"><input v-model="selected" type="checkbox" :value="d.id" :disabled="!d.sendable" /></td>
                            <td class="px-3">{{ d.date }}</td>
                            <td class="px-3">{{ d.type }}</td>
                            <td class="px-3">{{ d.consecutive }}</td>
                            <td class="max-w-[200px] truncate px-3">{{ d.employee }}</td>
                            <td class="px-3">{{ d.identification }}</td>
                            <td class="px-3">{{ d.contract }}</td>
                            <td class="px-3 text-right font-semibold text-primary">{{ money(d.net) }}</td>
                            <td class="px-3" :title="d.message">
                                <Icon :icon="d.status === 'Aprobado' ? 'circle-check' : d.status === 'Rechazado' ? 'circle-xmark' : 'clock-rotate-left'"
                                    :class="d.status === 'Aprobado' ? 'text-[#5a9e2b]' : d.status === 'Rechazado' ? 'text-danger' : 'text-muted'" />
                                {{ d.status }}
                            </td>
                            <td class="px-3">
                                <RowMenu :open="menu === d.id" :min-width="150" anchor-class="inline-flex" @close="menu = null">
                                <template #anchor>
                                <button type="button" class="font-semibold text-primary disabled:text-muted" :disabled="!d.adjustable" :title="d.adjustable ? null : (d.status === 'Aprobado' ? 'El documento no tiene CUNE registrado; la DIAN exige el CUNE del documento a ajustar.' : 'Solo se pueden ajustar documentos aprobados por la DIAN.')" @click="adjusting = d">Nota de ajuste</button>
                                <button type="button" class="ml-[4px] text-primary" @click="menu = menu === d.id ? null : d.id"><Icon icon="caret-down" /></button>
                                </template>
                                <ul class="text-[13px]">
                                    <li><button type="button" class="w-full px-[12px] py-[6px] text-left disabled:text-muted" :disabled="!d.adjustable" @click="menu = null; adjusting = d">Nota de ajuste</button></li>
                                    <li><Link :href="`/nomina/documentos/${d.id}`" class="block px-[12px] py-[6px] hover:bg-[#f2f2f2]">Ver detalle</Link></li>
                                    <li><a :href="`/nomina/documentos/${d.id}?imprimir=1`" target="_blank" class="block px-[12px] py-[6px] hover:bg-[#f2f2f2]">Imprimir</a></li>
                                    <li><button type="button" class="w-full px-[12px] py-[6px] text-left disabled:text-muted" :disabled="!d.sendable" @click="remove(d)">Eliminar</button></li>
                                    <li><a :href="`/nomina/documentos/${d.id}/pdf`" class="block px-[12px] py-[6px] hover:bg-[#f2f2f2]">Descargar PDF</a></li>
                                    <li><a v-if="d.xml" :href="`/nomina/documentos/${d.id}/xml`" class="block px-[12px] py-[6px] hover:bg-[#f2f2f2]">Descargar XML</a>
                                        <span v-else class="block px-[12px] py-[6px] text-muted" title="El XML solo existe para documentos enviados desde este sistema.">Descargar XML</span></li>
                                </ul>
                                </RowMenu>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="!docs.length" />
        </template>

        <template v-else-if="tab === 'correo'">
            <div class="flex flex-wrap items-center gap-4 p-[16px]">
                <DownloadMenu button-class="text-[18px] text-muted hover:text-primary" label="" title="Descargar" @download="(x) => download(`/nomina/documentos/excel?${query({ tab: 'correo', ...mail.filters })}`, x)" />
                <button type="button" class="s-btn s-btn-primary" :disabled="!mailCount || mailing || !mail.smtp" :title="mail.smtp ? null : 'Configura el correo de salida (SMTP) para enviar documentos.'" @click="sendMail">Enviar al correo electrónico ({{ mailCount }})</button>
                <form class="flex" @submit.prevent="mailReload()">
                    <input v-model="mf.q" class="s-input w-[200px] rounded-r-none sm:w-[260px]" placeholder="Buscar por Nombre/identificación" />
                    <button type="submit" class="s-btn s-btn-primary rounded-l-none px-3"><Icon icon="magnifying-glass" /></button>
                </form>
                <div class="relative">
                    <button type="button" class="s-btn s-btn-outline" @click="showFilters = !showFilters"><Icon icon="filter" /> Filtros<span v-if="activeFilters"> ({{ activeFilters }})</span></button>
                    <div v-if="showFilters" class="absolute left-0 top-[40px] z-30 w-[360px] max-w-[calc(100vw-48px)] rounded-[4px] bg-white p-[16px] text-[13px] shadow-xl">
                        <p class="font-semibold">Define los filtros que deseas aplicar al reporte:</p>
                        <p class="mt-[10px] s-label">Fecha documento</p>
                        <div class="flex items-center gap-[6px]"><input v-model="mf.desde" type="date" class="s-input flex-1" /> a <input v-model="mf.hasta" type="date" class="s-input flex-1" /></div>
                        <label class="mt-[10px] block"><span class="s-label">Tipo de documento</span>
                            <select v-model="mf.tipo" class="s-input w-full"><option value="">Todos</option><option>Soporte de pago</option><option>Nota de ajuste</option></select></label>
                        <label class="mt-[10px] block"><span class="s-label">Estado</span>
                            <select v-model="mf.estado" class="s-input w-full"><option value="">Todos</option><option>Enviado</option><option>Envío fallido</option><option>Sin enviar</option></select></label>
                        <div class="mt-[14px] flex justify-end gap-[8px]">
                            <button type="button" class="s-btn s-btn-outline" @click="clearFilters">Limpiar filtro</button>
                            <button type="button" class="s-btn s-btn-primary" @click="showFilters = false; mailReload()">Filtrar</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="s-table-wrap">
                <table class="sticky-actions w-full border-collapse">
                    <thead>
                        <tr class="bg-primary text-left text-[14px] font-semibold text-white">
                            <th class="w-[40px] px-3 py-[9px]"><input type="checkbox" :checked="allMail" :title="allMail ? 'Todos los documentos filtrados' : null" @change="toggleAllMail" /></th>
                            <th class="px-3">Fecha documento</th>
                            <th class="px-3">Periodo</th>
                            <th class="px-3">Tipo</th>
                            <th class="px-3">Consecutivo</th>
                            <th class="px-3">Empleado</th>
                            <th class="px-3"># Identificación</th>
                            <th class="px-3">#Contrato</th>
                            <th class="px-3">Correo electrónico</th>
                            <th class="px-3">Envió al empleado</th>
                            <th class="px-3">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in mail.rows" :key="r.id" class="border-b border-line text-[13px]">
                            <td class="px-3 py-[9px]"><input v-if="allMail" type="checkbox" checked disabled /><input v-else v-model="mailSelected" type="checkbox" :value="r.id" /></td>
                            <td class="px-3">{{ r.date }}</td>
                            <td class="px-3">{{ r.period }}</td>
                            <td class="whitespace-nowrap px-3">{{ r.type }}</td>
                            <td class="px-3">{{ r.consecutive }}</td>
                            <td class="max-w-[180px] truncate px-3">{{ r.employee }}</td>
                            <td class="px-3">{{ r.identification }}</td>
                            <td class="px-3">{{ r.contract }}</td>
                            <td class="max-w-[200px] px-3">
                                <button type="button" class="flex max-w-full items-center gap-[6px] text-left hover:text-primary" title="Modificar correo electrónico" @click="editEmail(r)">
                                    <span class="truncate">{{ r.email || 'Sin correo' }}</span><Icon icon="pen" class="text-[11px] text-primary" />
                                </button>
                            </td>
                            <td class="px-3" :title="r.sent_at ? `Enviado el ${r.sent_at}` : null"><Icon :icon="statusIcon(r.mail_status)[0]" :class="statusIcon(r.mail_status)[1]" /> {{ r.mail_status }}</td>
                            <td class="whitespace-nowrap px-3">
                                <button type="button" class="font-semibold text-primary disabled:text-muted" :disabled="mailing || !mail.smtp" @click="sendOne(r)">Enviar</button>
                                <a :href="`/nomina/documentos/${r.id}/pdf`" class="ml-[12px] font-semibold text-primary">Descargar</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="!mail.rows.length" />
            <div v-if="mail.total" class="flex items-center justify-end gap-[12px] p-[12px] text-[13px]">
                <span>{{ mail.from }}-{{ mail.to }} de {{ mail.total }}</span>
                <button type="button" class="text-primary disabled:text-muted" :disabled="mail.page <= 1" @click="mailReload(mail.page - 1)"><Icon icon="chevron-left" /></button>
                <span>Página {{ mail.page }} de {{ mail.last }}</span>
                <button type="button" class="text-primary disabled:text-muted" :disabled="mail.page >= mail.last" @click="mailReload(mail.page + 1)"><Icon icon="chevron-right" /></button>
            </div>
        </template>

        <form v-else class="grid grid-cols-1 gap-[20px] p-[20px] lg:grid-cols-2" @submit.prevent="saveConfig">
            <div class="self-start rounded-[4px] border border-line">
                <p class="border-b border-line px-[20px] py-[14px] text-[16px] font-semibold">Provisiones mensuales</p>
                <label class="flex items-center gap-[8px] px-[20px] py-[18px] text-[13px]">
                    <input v-model="cf.sum_provisions" type="checkbox" />
                    Sumar el valor de las provisiones en los documentos
                    <Icon icon="circle-info" class="text-primary" title="Al generar los documentos se reportan como devengados la prima, las cesantías, los intereses y las vacaciones provisionados en las nóminas del periodo." />
                </label>
            </div>
            <div class="rounded-[4px] border border-line">
                <p class="border-b border-line px-[20px] py-[14px] text-[16px] font-semibold">Prefijos</p>
                <div v-for="[title, p, s, locked, other] in [['Documento soporte de Nómina', 'prefix', 'start', config.locked, 'nota de ajuste'], ['Nota de ajuste de Nómina', 'note_prefix', 'note_start', config.note_locked, 'documento soporte']]" :key="p" class="px-[20px] pt-[16px]">
                    <p class="text-[15px] font-semibold">{{ title }}</p>
                    <div class="mt-[8px] grid grid-cols-2 gap-[12px]">
                        <label><span class="s-label">Prefijo <span v-if="!locked" class="req">*</span></span>
                            <input v-model="cf[p]" class="s-input w-full uppercase disabled:bg-[#e9ecef] disabled:text-muted" maxlength="4" :disabled="locked" @input="cf[p] = cf[p].toUpperCase()" />
                            <span v-if="cf.errors[p]" class="text-[12px] text-danger">{{ cf.errors[p] }}</span></label>
                        <label><span class="s-label">Numeración inicial <span v-if="!locked" class="req">*</span></span>
                            <input v-model.number="cf[s]" type="number" min="1" class="s-input w-full disabled:bg-[#e9ecef] disabled:text-muted" :disabled="locked" />
                            <span v-if="cf.errors[s]" class="text-[12px] text-danger">{{ cf.errors[s] }}</span></label>
                    </div>
                    <ul class="mt-[10px] list-disc pl-[18px] text-[12px] text-muted">
                        <li>Ten en cuenta que una vez se acepte el primer documento por parte de la DIAN, no se podrán modificar estos datos.</li>
                        <li>Debes usar un prefijo diferente al que usas en {{ other }} de Nómina.</li>
                    </ul>
                </div>
                <div class="pb-[16px]"></div>
            </div>
            <div class="flex justify-end lg:col-span-2">
                <button type="submit" class="s-btn s-btn-primary" :disabled="cf.processing">Guardar cambios</button>
            </div>
        </form>
    </section>

    <div v-if="generating" class="fixed inset-0 z-50 flex items-start justify-center p-3 bg-black/40 pt-[60px] md:pt-[160px]" @click.self="generating = null">
        <div class="w-[480px] max-w-full max-h-[calc(100vh-24px)] overflow-y-auto rounded-[4px] bg-white p-[20px] text-[13px] shadow-xl">
            <div class="flex items-start justify-between"><p class="text-[16px] font-semibold">Generar documentos</p><button type="button" class="text-primary" @click="generating = null"><Icon icon="xmark" /></button></div>
            <p class="mt-[12px] rounded-[3px] bg-[#e9f5fe] px-[10px] py-[8px]"><Icon icon="circle-info" class="text-[#2196f3]" /> Solamente se generarán las nóminas que se encuentren en estado terminado</p>
            <p class="mt-[12px]">Selecciona el periodo de nómina que necesites generar y enviar a la DIAN.</p>
            <label class="mt-[10px] block">
                <span class="s-label">Periodo de nómina <span class="req">*</span></span>
                <select v-model="generating" class="s-input w-full"><option v-for="p in periods" :key="p" :value="p">{{ label(p) }}</option></select>
            </label>
            <div class="mt-[18px] flex justify-end gap-[8px]">
                <button type="button" class="s-btn s-btn-outline" @click="generating = null">Cancelar</button>
                <button type="button" class="s-btn s-btn-primary" @click="generate">Generar</button>
            </div>
        </div>
    </div>

    <div v-if="adjusting" class="fixed inset-0 z-50 flex items-start justify-center p-3 bg-black/40 pt-[60px] md:pt-[160px]" @click.self="adjusting = null">
        <div class="w-[480px] max-w-full max-h-[calc(100vh-24px)] overflow-y-auto rounded-[4px] bg-white p-[20px] text-[13px] shadow-xl">
            <div class="flex items-start justify-between"><p class="text-[16px] font-semibold">Nota de ajuste {{ adjusting.consecutive }}</p><button type="button" class="text-primary" @click="adjusting = null"><Icon icon="xmark" /></button></div>
            <p class="mt-[12px]">{{ adjusting.employee }} · {{ adjusting.identification }}</p>
            <label class="mt-[12px] flex items-start gap-[8px]"><input v-model="adjustType" type="radio" value="1" class="mt-[3px]" /><span><b>Reemplazar</b><br />Se envía de nuevo el documento con los valores actuales de las nóminas del periodo.</span></label>
            <label class="mt-[10px] flex items-start gap-[8px]"><input v-model="adjustType" type="radio" value="2" class="mt-[3px]" /><span><b>Eliminar</b><br />Anula ante la DIAN el documento enviado por error.</span></label>
            <div class="mt-[18px] flex justify-end gap-[8px]">
                <button type="button" class="s-btn s-btn-outline" @click="adjusting = null">Cancelar</button>
                <button type="button" class="s-btn s-btn-primary" @click="adjust">Crear nota de ajuste</button>
            </div>
        </div>
    </div>

    <div v-if="editing" class="fixed inset-0 z-50 flex items-start justify-center p-3 bg-black/40 pt-[60px] md:pt-[160px]" @click.self="editing = null">
        <form class="w-[480px] max-w-full max-h-[calc(100vh-24px)] overflow-y-auto rounded-[4px] bg-white p-[20px] text-[13px] shadow-xl" @submit.prevent="saveEmail">
            <div class="flex items-start justify-between"><p class="text-[16px] font-semibold">Modificar correo electrónico</p><button type="button" class="text-primary" @click="editing = null"><Icon icon="xmark" /></button></div>
            <p class="mt-[10px]">{{ editing.consecutive }} · {{ editing.employee }}</p>
            <label class="mt-[12px] block"><span class="s-label">Correo electrónico <span class="req">*</span></span>
                <input v-model="emailForm.email" type="email" class="s-input w-full" required />
                <span v-if="emailForm.errors.email" class="text-[12px] text-danger">{{ emailForm.errors.email }}</span></label>
            <label class="mt-[12px] flex items-center gap-[8px]"><input v-model="emailForm.replace" type="checkbox" /> Reemplazar correo electrónico del empleado</label>
            <div class="mt-[18px] flex justify-end gap-[8px]">
                <button type="button" class="s-btn s-btn-outline" @click="editing = null">Cancelar</button>
                <button type="submit" class="s-btn s-btn-primary" :disabled="emailForm.processing || !mail.smtp">Guardar y enviar</button>
            </div>
        </form>
    </div>
</template>
