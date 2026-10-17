<script setup>
import { Head } from '@inertiajs/vue3';
import PageHeader from '../../../Components/PageHeader.vue';

defineProps({ entry: { type: Object, required: true } });
const money = (v) => (v ? Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '');
</script>

<template>
    <Head :title="entry.name" />
    <PageHeader :title="`Comprobante ${entry.name}`" back="/contabilidad/comprobantes" />

    <section class="s-card mt-[18px] p-4">
        <p><b>Fecha:</b> {{ entry.date }}</p>
        <p v-if="entry.observations" class="mt-1"><b>Observaciones:</b> {{ entry.observations }}</p>
    </section>
    <section class="s-card mt-[14px]">
        <div class="s-table-wrap">
            <table class="s-table">
                <thead><tr><th>Cuenta contable</th><th>Tercero</th><th>Descripción</th><th class="text-right">Débito</th><th class="text-right">Crédito</th></tr></thead>
                <tbody>
                    <tr v-for="(l, i) in entry.lines" :key="i">
                        <td>{{ l.account }}</td><td>{{ l.contact }}</td><td>{{ l.description }}</td>
                        <td class="text-right">{{ money(l.debit) }}</td><td class="text-right">{{ money(l.credit) }}</td>
                    </tr>
                    <tr class="font-bold">
                        <td colspan="3" class="text-right">Totales</td>
                        <td class="text-right">{{ money(entry.debit) }}</td><td class="text-right">{{ money(entry.credit) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
