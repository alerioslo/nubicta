<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

/* Botón y ventana "Cruce deuda con anticipos" de Siigo: abre un comprobante contable con el cruce ya armado para revisarlo y guardarlo. */
const props = defineProps({
    module: { type: String, required: true },
    contactId: { type: [Number, null], default: null },
    contactLabel: { type: String, default: '' },
    date: { type: String, default: '' },
    label: { type: String, default: 'Cruce deuda con anticipos' },
});
const money = (v) => Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const modal = ref(null);
const error = ref('');

async function open() {
    error.value = '';
    const res = await fetch(`/api/buscar/anticipos?modulo=${props.module}&contact=${props.contactId}`, { headers: { Accept: 'application/json' } });
    modal.value = { ...(await res.json()), date: props.date, type: '' };
}

function confirm() {
    const m = modal.value;
    if (!m.type) return (error.value = 'Selecciona el tipo de comprobante');
    if (m.advance <= 0) return (error.value = 'El tercero no tiene anticipos para cruzar');
    if (m.debt <= 0) return (error.value = props.module === 'ventas' ? 'El cliente no tiene cartera pendiente' : 'El proveedor no tiene deudas pendientes');
    router.visit(`/contabilidad/comprobantes/crear?cruce=${props.module}&tercero=${props.contactId}&tipo=${m.type}&fecha=${m.date}`);
}
</script>

<template>
    <button v-if="contactId" type="button" class="doc-btn doc-btn-blue h-[24px] px-[10px]" @click="open">{{ label }}</button>
    <Teleport to="body">
        <div v-if="modal" class="fixed inset-0 z-50 flex items-start justify-center p-3 bg-black/40 pt-[60px] md:pt-[190px] text-[12px] text-[#333]">
            <div class="w-[500px] max-w-full max-h-[calc(100vh-24px)] overflow-y-auto bg-white px-[22px] py-[16px] shadow-xl">
                <div class="grid grid-cols-[130px_1fr] items-center gap-x-[14px] gap-y-[16px]">
                    <span class="text-right font-semibold">Fecha de elaboración</span>
                    <input v-model="modal.date" type="date" class="doc-input w-[180px]" />
                    <span class="text-right font-semibold">Tercero</span>
                    <input class="doc-input" :value="contactLabel" disabled />
                    <span class="text-right font-semibold">{{ module === 'ventas' ? 'Cartera por cobrar' : 'Deuda por pagar' }}</span>
                    <span>{{ money(modal.debt) }}</span>
                    <span class="text-right font-semibold">Anticipo</span>
                    <span>{{ money(modal.advance) }}</span>
                    <span class="text-right font-semibold">Tipo</span>
                    <select v-model="modal.type" class="doc-input">
                        <option value=""></option>
                        <option v-for="t in modal.types" :key="t.id" :value="t.id">{{ t.label }}</option>
                    </select>
                </div>
                <p v-if="error" class="mt-[10px] text-danger">{{ error }}</p>
                <div class="mt-[18px] flex gap-[4px]">
                    <button type="button" class="doc-btn doc-btn-green h-[26px] px-[8px]" @click="confirm">{{ label }}</button>
                    <button type="button" class="doc-btn doc-btn-blue h-[26px] px-[12px]" @click="modal = null">Volver</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
