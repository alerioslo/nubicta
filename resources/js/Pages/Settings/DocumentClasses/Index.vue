<script setup>
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '../../../Components/EmptyState.vue';

const props = defineProps({
    slug: { type: String, required: true },
    title: { type: String, required: true },
    columns: { type: Array, required: true },
    rows: { type: Array, required: true },
    export: { type: Boolean, default: false },
    style: { type: String, default: 'grid' },
});

const base = `/configuracion/documentos/${props.slug}`;
</script>

<template>
    <Head :title="title" />
    <div class="flex items-center justify-between pt-[6px]">
        <h1 class="text-[25px] font-normal text-primary">{{ title }}</h1>
        <Link v-if="style === 'simple'" :href="`${base}/crear`" class="s-btn s-btn-primary">Crear nuevo tipo de comprobante</Link>
    </div>
    <Link v-if="style !== 'simple'" :href="`${base}/crear`" class="s-btn mt-[14px] rounded-none bg-[#6cc04a] text-white hover:bg-[#5cae3c]">Crear nuevo tipo de comprobante</Link>

    <section class="s-card mt-[18px] p-[18px]">
        <div v-if="export" class="mb-[12px] flex justify-end">
            <a :href="`${base}?formato=xlsx`" class="s-btn s-btn-outline h-[34px] w-[36px] px-0" title="Exportar a Excel"><Icon icon="file-excel" /></a>
        </div>
        <table class="s-table">
            <thead>
                <tr>
                    <th v-for="c in columns" :key="c.key">{{ c.label }}</th>
                    <th class="w-[110px]">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in rows" :key="r.id" :class="{ 'text-muted': !r.active }">
                    <td v-for="c in columns" :key="c.key">
                        <span v-if="c.key === 'numbering_status' && r[c.key]" class="s-badge" :class="r[c.key] === 'Vigente' ? 's-badge-ok' : r[c.key] === 'Vencida' ? 's-badge-danger' : 's-badge-warn'">{{ r[c.key] }}</span>
                        <template v-else>{{ r[c.key] }}</template>
                    </td>
                    <td><Link :href="`${base}/${r.id}/editar`" class="text-primary">Editar</Link></td>
                </tr>
            </tbody>
        </table>
        <EmptyState v-if="!rows.length" />
    </section>
</template>
