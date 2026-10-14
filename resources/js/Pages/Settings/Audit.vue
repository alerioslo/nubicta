<script setup>
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import Pager from '../../Components/Pager.vue';
import EmptyState from '../../Components/EmptyState.vue';

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const f = reactive({ event: props.filters.event ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
const events = { created: 'Creación', updated: 'Modificación', deleted: 'Eliminación', login: 'Ingreso', logout: 'Salida', annulled: 'Anulación' };

function apply() {
    router.get('/configuracion/auditoria', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true });
}
const summary = (obj) => (obj ? Object.entries(obj).map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`).join(' · ') : '');
</script>

<template>
    <Head title="Historial de actividades" />
    <PageHeader title="Historial de actividades realizadas en el aplicativo" back="/configuracion" />

    <section class="s-card mt-[18px]">
        <div class="flex items-end gap-3 p-[16px]">
            <label class="w-[180px]"><span class="s-label">Acción</span>
                <select v-model="f.event" class="s-input"><option value="">Todas</option><option v-for="(n, k) in events" :key="k" :value="k">{{ n }}</option></select>
            </label>
            <label><span class="s-label">Desde</span><input v-model="f.from" type="date" class="s-input" /></label>
            <label><span class="s-label">Hasta</span><input v-model="f.to" type="date" class="s-input" /></label>
            <button type="button" class="s-btn s-btn-outline" @click="apply"><Icon icon="filter" /> Filtrar</button>
        </div>
        <table class="s-table">
            <thead>
                <tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Registro</th><th>Detalle</th><th>IP</th></tr>
            </thead>
            <tbody>
                <tr v-for="l in logs.data" :key="l.id">
                    <td class="whitespace-nowrap">{{ l.date }}</td>
                    <td>{{ l.user ?? 'Sistema' }}</td>
                    <td>{{ events[l.event] ?? l.event }}</td>
                    <td>{{ l.entity }}</td>
                    <td class="max-w-[520px] truncate text-[12px] text-muted" :title="summary(l.new) || summary(l.old)">{{ summary(l.new) || summary(l.old) }}</td>
                    <td>{{ l.ip }}</td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!logs.data.length" />
        <Pager :paginator="logs" />
    </section>
</template>
