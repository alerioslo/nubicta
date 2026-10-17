<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '../../../composables/dialog';
import Field from '../../../Components/Field.vue';
import AccountPicker from '../../../Components/AccountPicker.vue';

const props = defineProps({
    slug: { type: String, required: true },
    title: { type: String, required: true },
    listTitle: { type: String, required: true },
    sections: { type: Array, required: true },
    style: { type: String, default: 'classic' },
    buttons: { type: String, default: 'save-first' },
    canDelete: { type: Boolean, default: false },
    deleteLabel: { type: String, default: 'Borrar' },
    canDuplicate: { type: Boolean, default: false },
    values: { type: Object, required: true },
    accountLabels: { type: Object, default: () => ({}) },
    recordId: { type: Number, default: null },
    resolution: { type: Object, default: null },
});

const listUrl = `/configuracion/documentos/${props.slug}`;
const form = useForm({ ...props.values, validity_months: '' });
const labels = reactive({ ...Object.fromEntries(Object.entries(props.accountLabels).map(([k, v]) => [k, v])) });
const accountLabel = (id) => (id ? labels[id] ?? '' : '');
const setAccountLabel = (key, v) => {
    if (form[key]) labels[form[key]] = v;
};
const tabs = reactive(Object.fromEntries(props.sections.map((s, i) => [i, 0])));
const showColumns = ref(false);
const pick = reactive({ available: null, chosen: null });

const mainSections = computed(() => props.sections.map((s, i) => ({ ...s, i })).filter((s) => !s.side));
const sideSections = computed(() => props.sections.map((s, i) => ({ ...s, i })).filter((s) => s.side));

watch(
    () => [form.valid_from, form.validity_months],
    ([from, months]) => {
        if (!from || !months) return;
        const d = new Date(`${from}T00:00:00`);
        d.setMonth(d.getMonth() + Number(months));
        d.setDate(d.getDate() - 1);
        form.valid_to = d.toISOString().slice(0, 10);
    },
);

watch(
    () => form.electronic,
    (v) => {
        if ('electronic_type' in props.values && props.sections.some((s) => s.fields?.some((f) => f.key === 'electronic' && f.type === 'radio'))) {
            form.electronic_type = v === 'si' ? 'ElectronicInvoice' : 'NoElectronic';
        }
    },
);
watch(
    () => form.electronic_type,
    (v) => {
        if (form.electronic !== undefined && props.sections.some((s) => s.fields?.some((f) => f.key === 'electronic' && f.type === 'radio'))) {
            form.electronic = v === 'NoElectronic' ? 'no' : 'si';
        }
    },
);

function submit() {
    const opts = { preserveScroll: true };
    if (props.recordId) form.put(`${listUrl}/${props.recordId}`, opts);
    else form.post(listUrl, opts);
}
async function destroy() {
    if (await confirmDialog('¿Deseas borrar este tipo de comprobante?')) router.delete(`${listUrl}/${props.recordId}`);
}
function duplicate() {
    router.get(`${listUrl}/crear`, { duplicar: props.recordId });
}

function addPhone() {
    form.phones = [...(form.phones ?? []), { indicative: '', number: '', extension: '' }];
}
function addCharge() {
    form.charges = [...(form.charges ?? []), { name: '', kind: 'Cargo', rate: 0, account_id: null, active: true }];
}
function moveColumn(dir) {
    const list = [...form.print_columns];
    const i = list.indexOf(pick.chosen);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= list.length) return;
    [list[i], list[j]] = [list[j], list[i]];
    form.print_columns = list;
}
function addColumn() {
    if (pick.available && !form.print_columns.includes(pick.available)) form.print_columns = [...form.print_columns, pick.available];
    pick.available = null;
}
function removeColumn() {
    form.print_columns = form.print_columns.filter((c) => c !== pick.chosen);
    pick.chosen = null;
}
const fieldsOf = (s) => (s.tabs?.length ? s.tabs[tabs[s.i]].fields : s.fields ?? []);
const err = (k) => form.errors[k];
const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
const fmtDate = (d) => (d ? `${Number(d.slice(8, 10))} ${MONTHS[Number(d.slice(5, 7)) - 1]}. ${d.slice(0, 4)}` : '');
</script>

<template>
    <Head :title="title" />

    <div v-if="style === 'panel'" class="-mx-3 md:-mx-[22px] flex h-[52px] items-center bg-primary px-[24px] text-white">
        <h1 class="text-[17px] font-semibold">{{ title }}</h1>
        <Link :href="listUrl" class="ml-auto text-[18px]" title="Cerrar"><Icon icon="xmark" /></Link>
    </div>
    <div v-else class="flex min-h-[44px] items-center gap-3 border-b border-[#dfe3e7] pb-[12px] pt-[6px]">
        <h1 class="text-[22px] font-bold text-ink">{{ title }}</h1>
        <div class="ml-auto flex items-center gap-2">
            <template v-if="buttons === 'back-first'">
                <Link :href="listUrl" class="s-btn s-btn-link"><Icon icon="chevron-left" /> Volver</Link>
                <button v-if="canDelete" type="button" class="s-btn s-btn-outline" @click="destroy">{{ deleteLabel }}</button>
                <button type="button" class="s-btn s-btn-primary" :disabled="form.processing" @click="submit">Guardar</button>
            </template>
            <template v-else>
                <template v-if="style === 'simple'"><Link :href="listUrl" class="s-btn s-btn-link"><Icon icon="chevron-left" /> Volver</Link></template>
                <button v-if="style !== 'simple'" type="button" class="s-btn s-btn-primary" :disabled="form.processing" @click="submit">Guardar</button>
                <button v-if="canDelete" type="button" class="s-btn s-btn-outline" @click="destroy">{{ deleteLabel }}</button>
                <button v-if="style === 'simple'" type="button" class="s-btn s-btn-primary" :disabled="form.processing" @click="submit">Guardar</button>
                <Link v-else :href="listUrl" class="s-btn s-btn-outline">Volver</Link>
            </template>
        </div>
    </div>
    <p v-if="style === 'simple'" class="mt-[8px] text-[12px] text-ink">Todos los campos marcados con <span class="text-[#ff8a00]">*</span> son obligatorios</p>

    <form class="mt-[18px] grid gap-[18px] pb-[70px]" :class="sideSections.length ? 'grid-cols-2' : 'grid-cols-1'" @submit.prevent="submit">
        <div class="flex flex-col gap-[18px]">
            <section v-for="s in mainSections" :key="s.i" :class="style === 'simple' ? '' : 's-card p-[22px]'">
                <div class="mb-4 flex items-center gap-3">
                    <h2 class="text-[16px] font-bold text-ink">{{ s.title }}</h2>
                    <a v-if="s.tutorials" href="/ayuda?q=documentos" class="text-[12px] text-primary">Ver tutoriales</a>
                </div>
                <div v-if="s.tabs?.length && !s.fields" class="s-subtabs mb-4">
                    <button v-for="(t, ti) in s.tabs" :key="t.title" type="button" class="s-subtab" :class="{ active: tabs[s.i] === ti }" @click="tabs[s.i] = ti">
                        <Icon v-if="t.icon" :icon="t.icon" class="mr-1" /> {{ t.title }}
                    </button>
                </div>
                <template v-for="(group, gi) in s.tabs?.length && s.fields ? [s.fields, null] : [fieldsOf(s)]" :key="gi">
                    <div v-if="group === null" class="s-subtabs mb-4 mt-2">
                        <button v-for="(t, ti) in s.tabs" :key="t.title" type="button" class="s-subtab" :class="{ active: tabs[s.i] === ti }" @click="tabs[s.i] = ti">
                            <Icon v-if="t.icon" :icon="t.icon" class="mr-1" /> {{ t.title }}
                        </button>
                    </div>
                    <div class="grid gap-x-4 gap-y-4" :class="style === 'simple' ? 'grid-cols-1 max-w-[420px]' : 'grid-cols-2'">
                        <template v-for="f in group ?? s.tabs[tabs[s.i]].fields" :key="f.key ?? f.heading">
                            <h3 v-if="f.heading" class="col-span-full mt-1 text-[14px] font-bold text-ink">{{ f.heading }}</h3>

                            <label v-else-if="f.type === 'switch'" class="flex items-center gap-2">
                                <span class="s-switch"><input v-model="form[f.key]" type="checkbox" /><span></span></span> {{ f.label }}
                            </label>

                            <label v-else-if="f.type === 'checkbox' && f.electronic" class="flex items-center gap-2 self-end pb-2" :class="{ 'col-span-full': true }">
                                <input v-model="form[f.key]" type="checkbox" true-value="si" false-value="no" class="accent-primary" /> {{ f.label }}
                            </label>
                            <label v-else-if="f.type === 'checkbox'" class="flex items-center gap-2 self-end pb-2" :class="{ 'col-span-full': f.span === 2 }">
                                <input v-model="form[f.key]" type="checkbox" class="accent-primary" /> {{ f.label }}
                            </label>

                            <div v-else-if="f.type === 'radio'" class="col-span-full flex flex-wrap items-center gap-x-6 gap-y-2" :class="{ 'flex-col items-start': Object.keys(f.options).length > 3 }">
                                <span v-if="f.key !== 'electronic'" class="s-label" :class="{ 'w-full': Object.keys(f.options).length <= 3 }">{{ f.label }}</span>
                                <label v-for="(n, k) in f.options" :key="k" class="flex items-center gap-2">
                                    <input v-model="form[f.key]" type="radio" :value="k" class="accent-primary" /> {{ n }}
                                </label>
                            </div>

                            <div v-else-if="f.type === 'yesno'" class="col-span-full flex items-center gap-6">
                                <label class="flex items-center gap-2"><input v-model="form[f.key]" type="radio" :value="true" class="accent-primary" /> Sí</label>
                                <label class="flex items-center gap-2"><input v-model="form[f.key]" type="radio" :value="false" class="accent-primary" /> No</label>
                            </div>

                            <div v-else-if="f.type === 'resolution'" class="col-span-full">
                                <a href="/configuracion/facturacion-electronica?tab=resoluciones" class="mb-3 inline-flex items-center gap-2 text-primary"><Icon icon="gear" /> {{ f.label }}</a>
                                <div v-if="resolution" class="s-table-wrap">
                                    <table class="s-table">
                                        <thead>
                                            <tr><th>Nro. de resolución DIAN</th><th>Inicio de vigencia</th><th>Final de vigencia</th><th>Vigencia a meses</th><th>Prefijo</th><th>Numeración inicial</th><th>Numeración final</th><th>Estado</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>{{ resolution.number }}</td><td>{{ fmtDate(resolution.from) }}</td><td>{{ fmtDate(resolution.to) }}</td>
                                                <td>{{ resolution.months ? `${resolution.months} meses` : '' }}</td><td>{{ resolution.prefix }}</td>
                                                <td>{{ resolution.range_from }}</td><td>{{ resolution.range_to }}</td>
                                                <td><span class="s-badge" :class="resolution.status === 'Vigente' ? 's-badge-ok' : 's-badge-warn'">{{ resolution.status }}</span></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p v-else class="text-muted">Este tipo de comprobante aún no tiene una resolución DIAN asociada.</p>
                            </div>

                            <div v-else-if="f.type === 'phones'" class="col-span-full">
                                <div class="s-table-wrap">
                                    <table class="s-table">
                                        <thead><tr><th class="w-[50px]">N°</th><th>Indicativo</th><th>Teléfono</th><th>Extensión</th><th class="w-[80px]">Acciones</th></tr></thead>
                                        <tbody>
                                            <tr v-for="(p, pi) in form.phones" :key="pi">
                                                <td>{{ pi + 1 }}</td>
                                                <td><input v-model="p.indicative" class="s-input" /></td>
                                                <td><input v-model="p.number" class="s-input" /></td>
                                                <td><input v-model="p.extension" class="s-input" /></td>
                                                <td><button type="button" class="text-danger" title="Eliminar" @click="form.phones.splice(pi, 1)"><Icon icon="trash" /></button></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <button type="button" class="mt-2 text-primary" @click="addPhone"><Icon icon="plus" /> Agregar teléfono</button>
                            </div>

                            <div v-else-if="f.type === 'charges'" class="col-span-full">
                                <div class="s-table-wrap">
                                    <table class="s-table">
                                        <thead><tr><th>Nombre</th><th>Tipo</th><th class="w-[100px]">% Tarifa</th><th>Cuenta contable</th><th class="w-[70px]">Activo</th><th class="w-[80px]">Acciones</th></tr></thead>
                                        <tbody>
                                            <tr v-for="(c, ci) in form.charges" :key="ci">
                                                <td><input v-model="c.name" class="s-input" /></td>
                                                <td><select v-model="c.kind" class="s-input"><option>Cargo</option><option>Descuento</option></select></td>
                                                <td><input v-model="c.rate" type="number" step="0.01" class="s-input" /></td>
                                                <td><AccountPicker v-model="c.account_id" :label="accountLabel(c.account_id)" @update:label="(v) => c.account_id && (labels[c.account_id] = v)" /></td>
                                                <td><input v-model="c.active" type="checkbox" class="accent-primary" /></td>
                                                <td><button type="button" class="text-danger" title="Eliminar" @click="form.charges.splice(ci, 1)"><Icon icon="trash" /></button></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <button type="button" class="mt-2 text-primary" @click="addCharge"><Icon icon="plus" /> Agregar</button>
                                <p v-if="err('charges.0.name')" class="s-error">{{ err('charges.0.name') }}</p>
                            </div>

                            <div v-else-if="f.type === 'columns' && !f.hidden" class="col-span-full">
                                <h3 class="mb-2 text-[14px] font-bold text-ink">{{ f.label }}</h3>
                                <div class="flex items-center gap-3">
                                    <div class="flex-1">
                                        <span class="s-label">Columnas disponibles</span>
                                        <select v-model="pick.available" size="8" class="s-input h-[180px]">
                                            <option v-for="o in f.options.filter((x) => !form.print_columns.includes(x))" :key="o" :value="o">{{ o }}</option>
                                        </select>
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <button type="button" class="s-btn s-btn-outline px-3" title="Agregar" @click="addColumn"><Icon icon="angle-right" /></button>
                                        <button type="button" class="s-btn s-btn-outline px-3" title="Quitar" @click="removeColumn"><Icon icon="angle-left" /></button>
                                    </div>
                                    <div class="flex-1">
                                        <span class="s-label">Columnas a visualizar</span>
                                        <select v-model="pick.chosen" size="8" class="s-input h-[180px]">
                                            <option v-for="(o, oi) in form.print_columns" :key="o" :value="o">A{{ oi + 1 }} {{ o }}</option>
                                        </select>
                                    </div>
                                    <div class="flex flex-col gap-2">
                                        <button type="button" class="s-btn s-btn-outline px-3" title="Subir" @click="moveColumn(-1)"><Icon icon="angle-up" /></button>
                                        <button type="button" class="s-btn s-btn-outline px-3" title="Bajar" @click="moveColumn(1)"><Icon icon="angle-down" /></button>
                                    </div>
                                </div>
                            </div>
                            <template v-else-if="f.type === 'columns'" />

                            <Field v-else :label="f.label" :required="f.required" :error="err(f.key)" :class="{ 'col-span-full': f.type === 'textarea' }">
                                <div v-if="f.type === 'select'" class="flex items-center gap-4">
                                    <select v-model="form[f.key]" class="s-input" :class="{ 'is-invalid': err(f.key) }">
                                        <option v-for="(n, k) in f.options" :key="k" :value="k">{{ n }}</option>
                                    </select>
                                    <button v-if="f.columns_link" type="button" class="whitespace-nowrap text-primary" @click="showColumns = true"><Icon icon="table-columns" /> Configurar columnas</button>
                                    <a v-if="f.preview && recordId" :href="`${listUrl}/${recordId}/vista-previa`" target="_blank" class="whitespace-nowrap text-primary" :class="{ 's-btn s-btn-outline ml-auto': style === 'panel' }">
                                        <Icon v-if="style === 'panel'" icon="eye" /> {{ style === 'panel' ? 'Ver formato' : 'Vista previa' }}
                                    </a>
                                </div>
                                <AccountPicker
                                    v-else-if="f.type === 'account'"
                                    v-model="form[f.key]"
                                    :label="accountLabel(form[f.key])"
                                    :invalid="!!err(f.key)"
                                    @update:label="(v) => setAccountLabel(f.key, v)"
                                />
                                <div v-else-if="f.type === 'textarea'">
                                    <textarea v-model="form[f.key]" rows="3" :maxlength="f.max" class="s-input" />
                                    <p v-if="f.max" class="text-right text-[11px] text-muted">{{ (form[f.key] ?? '').length }} / {{ f.max }}</p>
                                </div>
                                <input
                                    v-else
                                    v-model="form[f.key]"
                                    :type="f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : 'text'"
                                    :readonly="f.readonly"
                                    class="s-input"
                                    :class="{ 'is-invalid': err(f.key), 'bg-[#f4f6f8]': f.readonly }"
                                />
                                <p v-if="f.help" class="mt-1 text-[11px] text-muted">{{ f.help }}</p>
                            </Field>
                        </template>
                    </div>
                </template>
            </section>
        </div>
        <div v-if="sideSections.length" class="flex flex-col gap-[18px]">
            <section v-for="s in sideSections" :key="s.i">
                <h2 class="mb-4 text-[16px] font-bold text-ink">{{ s.title }}</h2>
                <template v-for="f in s.fields" :key="f.key">
                    <Field :label="f.label">
                        <div class="flex items-center gap-4">
                            <select v-model="form[f.key]" class="s-input">
                                <option v-for="(n, k) in f.options" :key="k" :value="k">{{ n }}</option>
                            </select>
                            <a v-if="recordId" :href="`${listUrl}/${recordId}/vista-previa`" target="_blank" class="whitespace-nowrap text-primary">Vista previa</a>
                        </div>
                    </Field>
                </template>
            </section>
        </div>
    </form>

    <div v-if="style === 'panel'" class="fixed bottom-0 left-[92px] right-0 z-20 flex h-[54px] items-center gap-3 border-t border-[#dfe3e7] bg-white px-[24px]">
        <Link :href="listUrl" class="text-primary">Volver</Link>
        <button v-if="canDuplicate" type="button" class="s-btn s-btn-outline ml-auto" @click="duplicate">Duplicar</button>
        <button type="button" class="s-btn s-btn-primary" :class="{ 'ml-auto': !canDuplicate }" :disabled="form.processing" @click="submit">Guardar</button>
    </div>
    <div v-else class="flex justify-end gap-2 border-t border-[#dfe3e7] pt-[12px]">
        <Link :href="listUrl" class="s-btn s-btn-link"><Icon icon="chevron-left" /> Volver</Link>
        <button v-if="canDelete" type="button" class="s-btn s-btn-outline" @click="destroy">{{ deleteLabel }}</button>
        <button type="button" class="s-btn s-btn-primary" :disabled="form.processing" @click="submit">Guardar</button>
    </div>

    <div v-if="showColumns" class="fixed inset-0 z-40 flex items-center justify-center p-3 bg-black/30" @click.self="showColumns = false">
        <div class="s-card w-[760px] max-w-full max-h-[calc(100vh-24px)] overflow-y-auto p-[22px]">
            <div class="mb-4 flex items-center">
                <h2 class="text-[16px] font-bold text-ink">Configurar columnas</h2>
                <button type="button" class="ml-auto text-muted" @click="showColumns = false"><Icon icon="xmark" /></button>
            </div>
            <template v-for="s in sections" :key="s.title">
                <template v-for="t in s.tabs ?? []" :key="t.title">
                    <template v-for="f in t.fields.filter((x) => x.type === 'columns')" :key="f.key">
                        <div class="flex items-center gap-3">
                            <div class="flex-1">
                                <span class="s-label">Columnas disponibles</span>
                                <select v-model="pick.available" size="8" class="s-input h-[180px]">
                                    <option v-for="o in f.options.filter((x) => !form.print_columns.includes(x))" :key="o" :value="o">{{ o }}</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-2">
                                <button type="button" class="s-btn s-btn-outline px-3" @click="addColumn"><Icon icon="angle-right" /></button>
                                <button type="button" class="s-btn s-btn-outline px-3" @click="removeColumn"><Icon icon="angle-left" /></button>
                            </div>
                            <div class="flex-1">
                                <span class="s-label">Columnas a visualizar</span>
                                <select v-model="pick.chosen" size="8" class="s-input h-[180px]">
                                    <option v-for="(o, oi) in form.print_columns" :key="o" :value="o">A{{ oi + 1 }} {{ o }}</option>
                                </select>
                            </div>
                            <div class="flex flex-col gap-2">
                                <button type="button" class="s-btn s-btn-outline px-3" @click="moveColumn(-1)"><Icon icon="angle-up" /></button>
                                <button type="button" class="s-btn s-btn-outline px-3" @click="moveColumn(1)"><Icon icon="angle-down" /></button>
                            </div>
                        </div>
                    </template>
                </template>
            </template>
            <div class="mt-4 flex justify-end"><button type="button" class="s-btn s-btn-primary" @click="showColumns = false">Aceptar</button></div>
        </div>
    </div>
</template>
