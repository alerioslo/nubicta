<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Field from '../../Components/Field.vue';

const props = defineProps({
    tab: { type: String, default: 'habilitacion' },
    settings: { type: Object, required: true },
    company: { type: Object, required: true },
    resolutions: { type: Array, required: true },
    transmissions: { type: Array, required: true },
    ranges: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
});

const TABS = [
    { key: 'habilitacion', label: 'Habilitación' },
    { key: 'certificado', label: 'Certificado digital' },
    { key: 'software', label: 'Software propio' },
    { key: 'resoluciones', label: 'Resoluciones de numeración' },
    { key: 'envios', label: 'Historial de envíos' },
];
const focus = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('ambito') : null;
if (focus) setTimeout(() => document.getElementById(`habilitacion-${focus}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 300);
const current = ref(TABS.some((t) => t.key === props.tab) ? props.tab : 'habilitacion');

const s = props.settings;
const software = useForm({
    environment: s.environment,
    simulated: s.simulated,
    software_id: s.software_id ?? '',
    software_pin: '',
    test_set_id: s.test_set_id ?? '',
    payroll_software_id: s.payroll_software_id ?? '',
    payroll_software_pin: '',
    payroll_test_set_id: s.payroll_test_set_id ?? '',
    support_software_id: s.support_software_id ?? '',
    support_software_pin: '',
    support_test_set_id: s.support_test_set_id ?? '',
    reception_email: s.reception_email ?? '',
    technical_contact: s.technical_contact ?? '',
});
const saveSoftware = () => software.put('/configuracion/facturacion-electronica/software', { preserveScroll: true });

const cert = useForm({ certificate: null, password: '' });
const showPass = ref(false);
const uploadCert = () =>
    cert.post('/configuracion/facturacion-electronica/certificado', { forceFormData: true, onSuccess: () => cert.reset() });

const busy = ref(null);
function action(url, key, method = 'post', data = {}) {
    busy.value = key;
    router[method](url, data, { preserveScroll: true, onFinish: () => (busy.value = null) });
}

const target = ref({});
// El rango de factura trae clave técnica; el de documento soporte no (su CUDS usa el PIN del software).
const typesFor = (range) => props.resolutions.filter((r) => r.type === (range.technical_key ? 'FV' : 'DS'));
props.ranges.forEach((r, i) => {
    const options = typesFor(r);
    if (options.length === 1) target.value[i] = options[0].id;
});

const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : '');
/* Cada paso lista sus requisitos (cumplido o no) y a donde ir para completarlos. */
const stepStatus = computed(() => {
    const c = props.company;
    const fe = s.habilitation.fe;
    const cert = s.certificate;
    const invoice = props.resolutions.find((r) => r.type === 'FV' && r.is_electronic && r.resolution_number) ?? props.resolutions.find((r) => r.type === 'FV' && r.is_electronic);
    const steps = [
        {
            label: 'Datos de la empresa', go: { href: '/configuracion/empresa', text: 'Editar datos de la empresa' },
            checks: [
                ['Razón social', c.business_name], ['NIT o cédula', c.nit && c.nit !== '000000000' ? c.nit : null], ['Dígito de verificación (DV)', c.dv],
                ['Dirección', c.address], ['Municipio', c.municipality ?? c.municipality_code], ['Correo electrónico', c.email], ['Régimen tributario', c.tax_regime ? 'Registrado' : null],
            ],
        },
        {
            label: 'Certificado digital', go: { tab: 'certificado', text: cert ? 'Ver o reemplazar certificado' : 'Cargar certificado' },
            checks: [
                ['Certificado cargado', cert?.common_name ?? (cert ? 'Cargado' : null)],
                ['Certificado vigente', cert?.valid_to && new Date(cert.valid_to) > new Date() ? `Hasta ${fmtDate(cert.valid_to)}` : null],
            ],
        },
        {
            label: 'Software propio', go: { tab: 'software', text: 'Editar software propio' },
            checks: [
                ['Identificador del software', s.software_id], ['PIN del software', s.has_pin ? 'Registrado' : null],
                ...(s.environment === 'produccion' ? [] : [['TestSetId de facturación', s.test_set_id]]),
            ],
        },
        {
            label: 'Set de pruebas', go: { scope: 'fe', text: 'Ver set de pruebas' }, done: ['aprobado'].includes(fe.status),
            checks: [
                ...fe.missing.map((m) => [m, null]),
                ['Set de facturación aprobado por la DIAN', fe.status === 'aprobado' ? `${fmtDate(fe.approved_at)}` : null, `${fe.accepted} de ${fe.required} aceptados${fe.rejected ? `, ${fe.rejected} rechazados` : ''}`],
            ],
            errors: fe.errors ?? [],
        },
        {
            label: 'Resolución de numeración', go: { tab: 'resoluciones', text: 'Ver resoluciones' },
            checks: [
                ['Resolución de facturación electrónica', invoice?.resolution_number],
                ['Prefijo y rango', invoice?.range ? `${invoice.prefix ?? ''} ${invoice.range}`.trim() : null],
                ['Resolución vigente', invoice?.valid_to && !invoice.expired ? `Hasta ${invoice.valid_to}` : null],
                ['Clave técnica', invoice?.has_key ? 'Registrada' : null],
            ],
        },
        {
            label: 'Producción', done: s.environment === 'produccion',
            checks: [['Set de pruebas aprobado', fe.status === 'aprobado' ? 'Sí' : null], ['Ambiente de producción activo', s.environment === 'produccion' ? `Desde ${s.production_since}` : null]],
        },
    ];

    return steps.map((st) => ({ ...st, done: st.done ?? st.checks.every(([, v]) => !!v) }));
});
const selected = ref(Math.max(0, stepStatus.value.findIndex((st) => !st.done)));
const step = computed(() => stepStatus.value[selected.value]);
function goStep(go) {
    if (go.tab) current.value = go.tab;
    else if (go.scope) document.getElementById(`habilitacion-${go.scope}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

const STATUS = {
    pendiente: ['Pendiente', 's-badge-off'],
    en_proceso: ['En proceso', 's-badge-warn'],
    aprobado: ['Aprobado', 's-badge-ok'],
    simulado: ['Aprobado (simulado)', 's-badge-warn'],
    rechazado: ['Rechazado', 's-badge-danger'],
};
</script>

<template>
    <Head title="Facturación electrónica" />
    <PageHeader title="Facturación electrónica" back="/configuracion">
        <template #actions>
            <span v-if="s.simulated" class="s-badge s-badge-warn">Modo simulado</span>
            <span class="s-badge" :class="s.environment === 'produccion' ? 's-badge-ok' : 's-badge-off'">
                {{ s.environment === 'produccion' ? 'Producción' : 'Habilitación' }}
            </span>
        </template>
    </PageHeader>

    <div class="s-subtabs mt-[10px]">
        <button v-for="t in TABS" :key="t.key" type="button" class="s-subtab" :class="{ active: current === t.key }" @click="current = t.key">
            {{ t.label }}
        </button>
    </div>

    <!-- Habilitación -->
    <div v-if="current === 'habilitacion'" class="mt-[18px] flex flex-col gap-[18px]">
        <section class="s-card p-[22px]">
            <h2 class="s-section-title mb-4">Proceso de habilitación ante la DIAN</h2>
            <ol class="grid grid-cols-6 gap-3">
                <li v-for="(st, i) in stepStatus" :key="st.label">
                    <button type="button" class="flex w-full flex-col items-center gap-2 rounded-[6px] px-1 py-2 text-center hover:bg-[#f0f9ff]" :class="{ 'bg-[#f0f9ff] ring-1 ring-primary': selected === i }" @click="selected = i">
                        <span
                            class="flex h-[34px] w-[34px] items-center justify-center rounded-full text-[14px] font-bold"
                            :class="st.done ? 'bg-[#7ac142] text-white' : selected === i ? 'bg-primary text-white' : 'bg-[#edf0f2] text-muted'"
                        >
                            <Icon v-if="st.done" icon="check" />
                            <template v-else>{{ i + 1 }}</template>
                        </span>
                        <span class="text-[12px]" :class="st.done || selected === i ? 'text-ink font-semibold' : 'text-muted'">{{ st.label }}</span>
                        <span v-if="!st.done" class="text-[10.5px] text-[#a86b00]">{{ st.checks.filter(([, v]) => !v).length }} pendiente(s)</span>
                    </button>
                </li>
            </ol>
            <div class="mt-4 rounded-[6px] border border-line bg-[#fafbfc] p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="text-[14px] font-bold text-ink">Paso {{ selected + 1 }}: {{ step.label }}</h3>
                    <span class="s-badge" :class="step.done ? 's-badge-ok' : 's-badge-warn'">{{ step.done ? 'Completo' : 'Falta completar' }}</span>
                </div>
                <ul class="grid grid-cols-1 gap-x-6 gap-y-[6px] text-[13px] md:grid-cols-2">
                    <li v-for="[label, value, note] in step.checks" :key="label" class="flex items-start gap-2">
                        <Icon :icon="value ? 'circle-check' : 'circle-xmark'" :class="value ? 'text-[#5a9e2b]' : 'text-[#c62828]'" class="mt-[3px]" />
                        <span>
                            <span :class="value ? 'text-ink' : 'font-semibold text-[#c62828]'">{{ label }}</span>
                            <span v-if="value && value !== true" class="text-muted">: {{ value }}</span>
                            <span v-else-if="!value" class="text-[#c62828]"> (falta)</span>
                            <span v-if="note" class="block text-[11.5px] text-muted">{{ note }}</span>
                        </span>
                    </li>
                </ul>
                <ul v-if="step.errors?.length" class="mt-3 flex flex-col gap-1 rounded-[4px] bg-[#fdecea] p-3 text-[12px] text-[#c62828]">
                    <li class="font-semibold">Últimos errores de la DIAN:</li>
                    <li v-for="e in step.errors" :key="e">{{ e }}</li>
                </ul>
                <div v-if="step.go" class="mt-3">
                    <Link v-if="step.go.href" :href="step.go.href" class="s-btn s-btn-outline"><Icon icon="pen" /> {{ step.go.text }}</Link>
                    <button v-else type="button" class="s-btn s-btn-outline" @click="goStep(step.go)"><Icon icon="pen" /> {{ step.go.text }}</button>
                </div>
            </div>
            <div class="mt-5 flex items-center gap-3 border-t border-line pt-4">
                <button type="button" class="s-btn s-btn-outline" :disabled="busy" @click="action('/configuracion/facturacion-electronica/probar-conexion', 'conn')">
                    <Icon icon="cloud" /> {{ busy === 'conn' ? 'Conectando…' : 'Probar conexión DIAN' }}
                </button>
                <button
                    v-if="s.environment !== 'produccion'"
                    type="button"
                    class="s-btn s-btn-primary"
                    :disabled="busy || s.habilitation.fe.status !== 'aprobado'"
                    @click="action('/configuracion/facturacion-electronica/produccion', 'prod')"
                >
                    Pasar a producción
                </button>
                <span v-else class="text-[13px] text-muted">En producción desde {{ s.production_since }}</span>
                <p v-if="s.simulated" class="ml-auto max-w-[520px] text-[12px] text-muted">
                    <Icon icon="circle-info" class="text-primary" />
                    Las respuestas de la DIAN son simuladas. Cuando cargues el certificado y los datos reales del software, desactiva el modo simulado en
                    <b>Software propio</b>.
                </p>
            </div>
        </section>

        <div class="grid grid-cols-3 gap-[18px]">
            <section v-for="(h, key) in s.habilitation" :id="`habilitacion-${key}`" :key="key" class="s-card flex flex-col p-[22px]" :class="{ 'ring-2 ring-primary': focus === key }">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="s-section-title">{{ h.label }}</h3>
                    <span class="s-badge" :class="STATUS[h.status]?.[1]">{{ STATUS[h.status]?.[0] ?? h.status }}</span>
                </div>
                <p class="text-[12px] text-muted">
                    Set de pruebas:
                    <span v-for="(n, k, i) in h.detail" :key="k">{{ i ? ', ' : '' }}{{ n }} {{ k }}</span>
                </p>
                <div class="my-3 h-[8px] overflow-hidden rounded-full bg-[#edf0f2]">
                    <div class="h-full bg-[#7ac142]" :style="{ width: Math.min(100, (h.accepted / h.required) * 100) + '%' }" />
                </div>
                <p class="text-[13px]">
                    <b>{{ h.accepted }}</b> aceptados · <b>{{ h.rejected }}</b> rechazados · {{ h.required }} requeridos
                </p>
                <p v-if="h.approved_at && h.status === 'aprobado'" class="mt-1 text-[12px] text-[#008a5c]">Aprobado por la DIAN el {{ fmtDate(h.approved_at) }}</p>
                <p v-if="h.status === 'simulado'" class="mt-1 text-[12px] text-[#a86b00]">Se corrió en modo simulado: no se envió nada a la DIAN. Desactiva el modo simulado y envíalo de nuevo.</p>
                <ul v-if="h.errors?.length" class="mt-2 flex flex-col gap-1 text-[12px] text-[#c62828]">
                    <li v-for="e in h.errors" :key="e">{{ e }}</li>
                </ul>
                <ul v-if="h.missing.length" class="mt-3 flex flex-col gap-1 text-[12px] text-[#a86b00]">
                    <li v-for="m in h.missing" :key="m"><Icon icon="triangle-exclamation" /> Falta: {{ m }}</li>
                </ul>
                <button
                    type="button"
                    class="s-btn s-btn-primary mt-auto self-start"
                    :disabled="busy || h.missing.length > 0"
                    @click="action(`/configuracion/facturacion-electronica/set-pruebas/${key}`, key)"
                >
                    {{ busy === key ? 'Enviando set…' : h.status === 'aprobado' ? 'Reenviar set de pruebas' : 'Enviar set de pruebas' }}
                </button>
            </section>
        </div>
    </div>

    <!-- Certificado -->
    <div v-else-if="current === 'certificado'" class="mt-[18px] grid grid-cols-[1fr_380px] gap-[18px]">
        <section class="s-card p-[22px]">
            <h2 class="s-section-title mb-4">Certificado de firma digital</h2>
            <template v-if="s.certificate">
                <dl class="grid grid-cols-[180px_1fr] gap-x-4 gap-y-2 text-[13px]">
                    <dt class="text-muted">Archivo</dt>
                    <dd>{{ s.certificate.file_name }}</dd>
                    <dt class="text-muted">Titular</dt>
                    <dd>{{ s.certificate.common_name }}</dd>
                    <dt class="text-muted">Entidad emisora</dt>
                    <dd>{{ s.certificate.issuer_name }}</dd>
                    <dt class="text-muted">Número de serie</dt>
                    <dd class="font-mono text-[12px]">{{ s.certificate.serial_number }}</dd>
                    <dt class="text-muted">Vigencia</dt>
                    <dd>{{ fmtDate(s.certificate.valid_from) }} - {{ fmtDate(s.certificate.valid_to) }}</dd>
                    <dt class="text-muted">Huella SHA-256</dt>
                    <dd class="break-all font-mono text-[11px]">{{ s.certificate.fingerprint_sha256 }}</dd>
                </dl>
                <ul v-if="s.certificate.warnings?.length" class="mt-4 flex flex-col gap-1 rounded-[6px] bg-[#fff4de] p-3 text-[12px] text-[#a86b00]">
                    <li v-for="w in s.certificate.warnings" :key="w"><Icon icon="triangle-exclamation" /> {{ w }}</li>
                </ul>
                <button type="button" class="s-btn s-btn-cancel mt-4" :disabled="busy" @click="action('/configuracion/facturacion-electronica/certificado', 'del', 'delete')">
                    <Icon icon="trash" /> Eliminar certificado
                </button>
            </template>
            <p v-else class="text-muted">Aún no se ha cargado un certificado digital.</p>
        </section>

        <section class="s-card h-fit p-[22px]">
            <h2 class="s-section-title mb-4">{{ s.certificate ? 'Reemplazar certificado' : 'Cargar certificado' }}</h2>
            <form class="flex flex-col gap-4" @submit.prevent="uploadCert">
                <Field label="Archivo .p12 / .pfx" required :error="cert.errors.certificate">
                    <input type="file" accept=".p12,.pfx" class="s-input pt-[6px]" @change="cert.certificate = $event.target.files[0] ?? null" />
                </Field>
                <Field label="Contraseña del certificado" required :error="cert.errors.password">
                    <div class="relative">
                        <input v-model="cert.password" :type="showPass ? 'text' : 'password'" class="s-input pr-9" autocomplete="new-password" />
                        <button type="button" class="absolute right-2 top-[8px] text-muted" @click="showPass = !showPass">
                            <Icon :icon="showPass ? 'eye-slash' : 'eye'" />
                        </button>
                    </div>
                </Field>
                <button type="submit" class="s-btn s-btn-primary" :disabled="cert.processing || !cert.certificate">
                    <Icon icon="file-arrow-up" /> {{ cert.processing ? 'Validando…' : 'Cargar certificado' }}
                </button>
                <p class="text-[12px] text-muted">El archivo y la contraseña se guardan cifrados. Solo se usan para firmar los documentos enviados a la DIAN.</p>
            </form>
            <div v-if="s.simulated" class="mt-4 border-t border-line pt-4">
                <button type="button" class="s-btn s-btn-outline w-full" :disabled="busy" @click="action('/configuracion/facturacion-electronica/certificado/prueba', 'testcert')">
                    Generar certificado de prueba
                </button>
                <p class="mt-2 text-[12px] text-muted">Solo para el modo simulado. Reemplázalo por el certificado real antes de pasar a producción.</p>
            </div>
        </section>
    </div>

    <!-- Software -->
    <form v-else-if="current === 'software'" class="mt-[18px] flex flex-col gap-[18px]" @submit.prevent="saveSoftware">
        <section class="s-card p-[22px]">
            <h2 class="s-section-title mb-4">Ambiente</h2>
            <div class="grid grid-cols-3 gap-4">
                <Field label="Ambiente DIAN" required>
                    <select v-model="software.environment" class="s-input">
                        <option value="habilitacion">Habilitación (pruebas)</option>
                        <option value="produccion">Producción</option>
                    </select>
                </Field>
                <Field label="Correo de recepción de documentos" :error="software.errors.reception_email">
                    <input v-model="software.reception_email" type="email" class="s-input" placeholder="facturacion@empresa.com" />
                </Field>
                <Field label="Contacto técnico"><input v-model="software.technical_contact" class="s-input" /></Field>
                <label class="col-span-3 flex items-center gap-3">
                    <span class="s-switch"><input v-model="software.simulated" type="checkbox" /><span /></span>
                    <span>Simular respuestas de la DIAN (no se envía nada a la DIAN)</span>
                </label>
            </div>
        </section>

        <section
            v-for="g in [
                { t: 'Facturación electrónica', id: 'software_id', pin: 'software_pin', set: 'test_set_id', has: s.has_pin },
                { t: 'Nómina electrónica', id: 'payroll_software_id', pin: 'payroll_software_pin', set: 'payroll_test_set_id', has: s.has_payroll_pin },
                { t: 'Documento soporte', id: 'support_software_id', pin: 'support_software_pin', set: 'support_test_set_id', has: s.has_support_pin },
            ]"
            :key="g.id"
            class="s-card p-[22px]"
        >
            <h2 class="s-section-title mb-4">{{ g.t }}</h2>
            <div class="grid grid-cols-3 gap-4">
                <Field label="Identificador del software (SoftwareID)" :error="software.errors[g.id]">
                    <input v-model="software[g.id]" class="s-input font-mono" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
                </Field>
                <Field label="PIN del software" :error="software.errors[g.pin]">
                    <input v-model="software[g.pin]" type="password" class="s-input" :placeholder="g.has ? '•••••• (guardado)' : ''" autocomplete="new-password" />
                </Field>
                <Field label="TestSetId" :error="software.errors[g.set]">
                    <input v-model="software[g.set]" class="s-input font-mono" />
                </Field>
            </div>
        </section>

        <div class="flex justify-end">
            <button type="submit" class="s-btn s-btn-primary" :disabled="software.processing">Guardar</button>
        </div>
    </form>

    <!-- Resoluciones -->
    <div v-else-if="current === 'resoluciones'" class="mt-[18px] flex flex-col gap-[18px]">
        <section class="s-card overflow-hidden">
            <div class="flex items-center justify-between p-[18px]">
                <h2 class="s-section-title">Resoluciones de numeración</h2>
                <button type="button" class="s-btn s-btn-outline" :disabled="busy" @click="action('/configuracion/facturacion-electronica/rangos', 'ranges')">
                    {{ busy === 'ranges' ? 'Consultando…' : 'Consultar rangos en la DIAN' }}
                </button>
            </div>
            <div class="s-table-wrap">
                <table class="s-table">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th>Documento</th>
                            <th>Numeración</th>
                            <th>Prefijo</th>
                            <th>Rangos de numeración</th>
                            <th>Inicio vigencia</th>
                            <th>Fin vigencia</th>
                            <th>Consecutivo</th>
                            <th>Clave técnica</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in resolutions" :key="r.id">
                            <td>{{ r.label }}</td>
                            <td>
                                {{ r.name }}<br /><span class="text-[12px] text-muted">{{ r.electronic }}</span>
                            </td>
                            <td>{{ r.resolution_number ?? '—' }}</td>
                            <td>{{ r.prefix ?? '—' }}</td>
                            <td>{{ r.range ?? '—' }}</td>
                            <td>{{ r.valid_from ?? '—' }}</td>
                            <td :class="{ 'text-danger': r.expired }">{{ r.valid_to ?? '—' }}</td>
                            <td>{{ r.consecutive }}</td>
                            <td>
                                <span class="s-badge" :class="r.has_key ? 's-badge-ok' : 's-badge-off'">{{ r.has_key ? 'Configurada' : 'Sin clave' }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="history.length" class="s-card overflow-hidden">
            <h2 class="s-section-title p-[18px]">Resoluciones de numeración autorizadas (historial)</h2>
            <div class="s-table-wrap">
                <table class="s-table">
                    <thead>
                        <tr>
                            <th>Tipo documento</th>
                            <th>Tipo de comprobante</th>
                            <th>Número de resolución</th>
                            <th>Fecha resolución</th>
                            <th>Prefijo</th>
                            <th class="text-right">Rango desde</th>
                            <th class="text-right">Rango hasta</th>
                            <th>Fecha desde</th>
                            <th>Fecha hasta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="h in history" :key="h.id" :class="{ 'text-muted': h.expired }">
                            <td>{{ h.kind }}</td>
                            <td>{{ h.linked ?? '' }}</td>
                            <td>{{ h.resolution }}</td>
                            <td>{{ h.date }}</td>
                            <td>{{ h.prefix }}</td>
                            <td class="text-right">{{ h.from }}</td>
                            <td class="text-right">{{ h.to }}</td>
                            <td>{{ h.valid_from }}</td>
                            <td>
                                {{ h.valid_to }}
                                <span v-if="h.expired" class="s-badge s-badge-off ml-1">Vencida</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="ranges.length" class="s-card overflow-hidden">
            <h2 class="s-section-title p-[18px]">Rangos autorizados por la DIAN</h2>
            <div class="s-table-wrap">
                <table class="s-table">
                    <thead>
                        <tr>
                            <th>Resolución</th>
                            <th>Fecha</th>
                            <th>Prefijo</th>
                            <th>Desde</th>
                            <th>Hasta</th>
                            <th>Vigencia</th>
                            <th>Asociar a</th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in ranges" :key="i">
                            <td>{{ r.resolution }}</td>
                            <td>{{ r.resolution_date }}</td>
                            <td>{{ r.prefix }}</td>
                            <td>{{ r.from }}</td>
                            <td>{{ r.to }}</td>
                            <td>{{ r.valid_from }} a {{ r.valid_to }}</td>
                            <td>
                                <select v-model="target[i]" class="s-input">
                                    <option :value="undefined">Selecciona</option>
                                    <option v-for="t in typesFor(r)" :key="t.id" :value="t.id">{{ t.label }} {{ t.name }}</option>
                                </select>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="s-btn s-btn-primary"
                                    :disabled="!target[i] || busy"
                                    @click="action('/configuracion/facturacion-electronica/rangos/aplicar', 'apply', 'post', { document_type_id: target[i], range: r })"
                                >
                                    Asociar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- Historial -->
    <section v-else class="s-card mt-[18px] overflow-hidden">
        <div class="s-table-wrap">
            <table class="s-table">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Operación</th>
                        <th>Ámbito</th>
                        <th>Documento</th>
                        <th>Código</th>
                        <th>Respuesta DIAN</th>
                        <th>Estado</th>
                        <th>Tiempo</th>
                        <th>Usuario</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!transmissions.length">
                        <td colspan="10" class="py-8 text-center text-muted">No hay envíos registrados.</td>
                    </tr>
                    <tr v-for="t in transmissions" :key="t.id">
                        <td class="whitespace-nowrap">{{ t.date }}</td>
                        <td>{{ t.operation }}</td>
                        <td>{{ t.scope }}</td>
                        <td>{{ t.document || '—' }}</td>
                        <td>{{ t.status_code ?? '—' }}</td>
                        <td class="max-w-[360px]">
                            {{ t.message }}
                            <span v-if="t.simulated" class="s-badge s-badge-warn ml-1">Simulado</span>
                        </td>
                        <td>
                            <span class="s-badge" :class="t.valid ? 's-badge-ok' : 's-badge-danger'">{{ t.valid ? 'Aceptado' : 'Rechazado' }}</span>
                        </td>
                        <td>{{ t.ms }} ms</td>
                        <td>{{ t.user ?? '—' }}</td>
                        <td><a :href="`/configuracion/facturacion-electronica/envios/${t.id}`" target="_blank" class="s-btn-link">XML</a></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
