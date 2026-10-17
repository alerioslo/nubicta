<script setup>
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '../../Components/EmptyState.vue';
import StatementPanel from '../../Components/StatementPanel.vue';

const props = defineProps({
    tab: { type: String, required: true },
    todos: { type: Boolean, default: false },
    contact: { type: Object, required: true },
    docs: { type: Array, default: () => [] },
    people: { type: Array, default: () => [] },
    tasks: { type: Array, default: () => [] },
});

const base = `/terceros/${props.contact.id}`;
const tabs = [
    { key: 'perfil', label: 'Perfil' },
    { key: 'cobrar', label: 'Cuentas por cobrar' },
    { key: 'pagar', label: 'Cuentas por pagar' },
    { key: 'contactos', label: 'Contactos' },
    { key: 'tareas', label: 'Tareas' },
];
const href = (k) => (k === 'perfil' ? base : `${base}?pestana=${k}`);
const roles = [
    { on: props.contact.is_customer, title: 'Clientes', text: 'Personas o empresas a las cuales necesitas generarles una cotización y/o una factura de venta' },
    { on: props.contact.is_supplier, title: 'Proveedores', text: 'Todas las personas o empresas a las cuales les compras bienes o servicios' },
    { on: props.contact.is_other, title: 'Otros', text: 'Por ejemplo: Fondos de salud y pensión, bancos, etc.' },
];
const v = (x) => x ?? '';
</script>

<template>
    <Head :title="contact.name" />
    <nav class="s-tabs -mx-3 -mt-[18px] bg-white md:-mx-[18px] px-[14px] pt-[16px]">
        <Link v-for="t in tabs" :key="t.key" :href="href(t.key)" class="s-tab" :class="{ active: tab === t.key }">{{ t.label }}</Link>
    </nav>

    <template v-if="tab === 'perfil'">
        <div class="mt-[26px] flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-[22px] font-medium text-primary">Perfil del tercero</h1>
            <Link :href="`${base}/editar`" class="s-btn s-btn-green h-[40px] px-[24px] text-[13px] font-semibold md:px-[48px]">Editar tercero</Link>
        </div>
        <div class="mt-[20px] grid grid-cols-1 items-center justify-items-center gap-[16px] text-center md:grid-cols-[1fr_auto_1fr] md:justify-items-stretch md:text-left">
            <div class="md:text-right">
                <div class="text-[18px] font-bold text-ink">{{ contact.name }}</div>
                <div class="mt-[6px] font-semibold text-ink">{{ contact.number }}</div>
                <div class="mt-[4px] font-semibold text-ink">Estado: <span :class="contact.active ? 'text-ok' : 'text-danger'">{{ contact.active ? 'Activo' : 'Inactivo' }}</span></div>
            </div>
            <div class="grid h-[100px] w-[100px] place-items-center rounded-full border border-[#c9ced3] bg-white text-[54px] text-[#c9ced3]"><Icon icon="user" /></div>
            <div class="text-[13px] text-muted">
                <div class="font-semibold">{{ contact.city }}</div>
                <div class="mt-[8px]">{{ contact.address }}</div>
                <div class="mt-[8px]">{{ contact.phone }} <template v-if="contact.email">- {{ contact.email }}</template></div>
            </div>
        </div>
        <div class="mt-[18px] grid grid-cols-2 border-b-2 border-[#c9ced3] pb-[14px] text-[12px]">
            <div><div class="text-[11px] text-primary">Creado</div><div class="mt-[10px] text-muted">{{ contact.created }}</div></div>
            <div><div class="text-[11px] text-primary">Modificado</div><div class="mt-[10px] text-muted">{{ contact.updated }}</div></div>
        </div>

        <section class="s-card mt-[22px] p-[24px]">
            <h2 class="text-[16px] font-medium text-ink">Tipo de tercero</h2>
            <div class="mt-[6px] grid grid-cols-1 gap-[12px] sm:grid-cols-3 sm:gap-[24px]">
                <div v-for="r in roles" :key="r.title" :class="r.on ? '' : 'opacity-40'">
                    <div class="text-[12.5px]" :class="r.on ? 'font-semibold text-primary' : 'text-muted'">{{ r.title }}</div>
                    <p class="mt-[12px] text-[12px] text-muted">{{ r.text }}</p>
                </div>
            </div>
        </section>

        <div class="mt-[22px] grid grid-cols-1 items-start gap-[16px] lg:grid-cols-2">
            <section class="s-card p-[24px]">
                <h2 class="text-[16px] font-medium text-ink">Datos básicos</h2>
                <div class="mt-[8px] grid grid-cols-2 text-[12px]">
                    <div class="space-y-[12px] border-r border-[#c9ced3] pr-[16px]">
                        <div><div class="font-semibold text-primary">Tipo</div><div class="text-muted">{{ contact.person }}</div></div>
                        <div><div class="font-semibold text-primary">Tipo de identificación</div><div class="text-muted">{{ contact.id_type }}</div></div>
                        <div class="grid grid-cols-2">
                            <div><div class="font-semibold text-primary"># Identificación</div><div class="text-muted">{{ contact.number }}</div></div>
                            <div><div class="font-semibold text-primary">Dv</div><div class="text-muted">{{ v(contact.dv) }}</div></div>
                        </div>
                        <div><div class="font-semibold text-primary">Código de la sucursal</div><div class="text-muted">{{ contact.branch }}</div></div>
                    </div>
                    <div class="space-y-[12px] pl-[24px]">
                        <div v-if="contact.business_name"><div class="font-semibold text-primary">Razón social</div><div class="text-muted">{{ contact.business_name }}</div></div>
                        <template v-else>
                            <div><div class="font-semibold text-primary">Nombres</div><div class="text-muted">{{ v(contact.first_name) }}</div></div>
                            <div><div class="font-semibold text-primary">Apellidos</div><div class="text-muted">{{ v(contact.last_name) }}</div></div>
                        </template>
                        <div><div class="font-semibold text-primary">Nombre comercial</div><div class="text-muted">{{ v(contact.trade_name) }}</div></div>
                        <div><div class="font-semibold text-primary">Ciudad</div><div class="text-muted">{{ v(contact.city) }}</div></div>
                        <div><div class="font-semibold text-primary">Dirección</div><div class="text-muted">{{ v(contact.address) }}</div></div>
                    </div>
                </div>
                <div class="mt-[18px] grid grid-cols-[80px_110px_1fr] px-[10px] text-[12px]">
                    <div><div class="font-semibold text-primary">Indicativo</div><div class="text-muted">{{ v(contact.phone_indicative) }}</div></div>
                    <div><div class="font-semibold text-primary"># de Teléfono</div><div class="text-muted">{{ v(contact.phone_number) }}</div></div>
                    <div><div class="font-semibold text-primary">Extensión</div><div class="text-muted">{{ v(contact.phone_extension) }}</div></div>
                </div>
            </section>
            <section class="s-card p-[24px]">
                <h2 class="text-[16px] font-medium text-ink">Datos para facturación y envío</h2>
                <div class="mt-[8px] grid grid-cols-2 text-[12px]">
                    <div class="space-y-[12px] border-r border-[#c9ced3] pr-[16px]">
                        <div><div class="font-semibold text-primary">Nombres del contacto</div><div class="text-muted">{{ v(contact.billing_first_name) }}</div></div>
                        <div><div class="font-semibold text-primary">Apellidos del contacto</div><div class="text-muted">{{ v(contact.billing_last_name) }}</div></div>
                        <div><div class="font-semibold text-primary">Correo electrónico</div><div class="break-all text-muted">{{ v(contact.email) }}</div></div>
                        <div><div class="font-semibold text-primary">Tipo de régimen IVA</div><div class="text-muted">{{ contact.regime }}</div></div>
                        <div class="grid grid-cols-[64px_1fr]">
                            <div><div class="font-semibold text-primary">Indicativo</div><div class="text-muted">{{ v(contact.phone_indicative) }}</div></div>
                            <div><div class="font-semibold text-primary"># de Teléfono</div><div class="text-muted">{{ v(contact.phone_number) }}</div></div>
                        </div>
                        <div class="grid grid-cols-[64px_1fr]">
                            <div><div class="font-semibold text-primary">Indicativo</div><div class="text-muted">+57</div></div>
                            <div><div class="font-semibold text-primary"># Celular</div><div class="text-muted">{{ v(contact.mobile) }}</div></div>
                        </div>
                        <div><div class="font-semibold text-primary">Código postal</div><div class="text-muted">{{ v(contact.postal_code) }}</div></div>
                    </div>
                    <div class="pl-[24px]">
                        <div class="font-semibold text-primary">Responsabilidad fiscal</div>
                        <div v-for="r in contact.responsibilities" :key="r" class="text-muted">{{ r }}</div>
                    </div>
                </div>
            </section>
        </div>

        <div class="mt-[22px] grid grid-cols-1 items-start gap-[16px] lg:grid-cols-2">
            <section class="s-card p-[24px]">
                <h2 class="text-[16px] font-medium text-ink"><Icon icon="angle-up" class="mr-[8px] text-primary" />Vendedor y cobrador</h2>
                <div class="mt-[8px] grid grid-cols-2 text-[12px]">
                    <div><div class="font-semibold text-primary">Vendedor</div><div class="text-muted">{{ v(contact.seller) }}</div></div>
                    <div><div class="font-semibold text-primary">Cobrador</div><div class="text-muted">{{ v(contact.collector) }}</div></div>
                </div>
            </section>
            <section class="s-card p-[24px]">
                <h2 class="text-[16px] font-medium text-ink"><Icon icon="angle-up" class="mr-[8px] text-primary" />Observaciones</h2>
                <p class="mt-[8px] whitespace-pre-line text-[12px] text-muted">{{ v(contact.observations) }}</p>
            </section>
        </div>
    </template>

    <template v-else-if="tab === 'cobrar' || tab === 'pagar'">
        <h1 class="mt-[18px] text-[22px] font-medium text-primary">{{ tab === 'cobrar' ? 'Estado de cuenta' : 'Cuentas por pagar' }}</h1>
        <StatementPanel :key="tab + todos" :side="tab === 'cobrar' ? 'clientes' : 'proveedores'" :url="tab === 'cobrar' ? '/ventas/clientes' : '/compras/proveedores'"
            :contact="contact" :docs="docs" :todos="todos" :base-url="href(tab)" :show-contact="false" />
    </template>

    <template v-else-if="tab === 'contactos'">
        <h1 class="mt-[18px] text-[22px] font-medium text-primary">Listado contactos</h1>
        <section class="s-card mt-[18px]">
            <div class="p-[12px] text-[12px] text-muted">Encontrados ({{ people.length }})</div>
            <div class="s-table-wrap">
                <table class="s-table">
                    <thead><tr><th class="w-[30px]"></th><th>Contacto</th><th>Cargo</th><th>Teléfono oficina</th><th>Teléfono móvil</th><th>Correo electrónico contacto</th></tr></thead>
                    <tbody>
                        <tr v-for="p in people" :key="p.id">
                            <td class="text-[#b8742a]"><Icon icon="user" /></td>
                            <td>{{ p.name }}</td><td>{{ v(p.position) }}</td><td>{{ v(p.office) }}</td><td>{{ v(p.mobile) }}</td><td>{{ v(p.email) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="!people.length" />
        </section>
    </template>

    <template v-else>
        <h1 class="mt-[18px] text-[22px] font-medium text-primary">Tareas relacionadas</h1>
        <section class="s-card mt-[18px]">
            <div class="p-[12px] text-[12px] text-muted">Encontrados ({{ tasks.length }})</div>
            <div class="s-table-wrap">
                <table class="s-table">
                    <thead><tr><th>Código tarea</th><th>Tipo de tarea</th><th>Actividad de la tarea</th><th>Descripción de la tarea</th><th>Fecha final</th><th>Fecha creación</th><th>Fecha modificado</th><th>Estado</th></tr></thead>
                    <tbody>
                        <tr v-for="t in tasks" :key="t.id">
                            <td>{{ t.id }}</td><td>{{ v(t.category) }}</td><td>{{ v(t.stage) }}</td><td>{{ v(t.description) }}</td><td>{{ v(t.due) }}</td><td>{{ t.created }}</td><td>{{ t.updated }}</td><td>{{ v(t.status) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-if="!tasks.length" />
        </section>
    </template>
</template>
