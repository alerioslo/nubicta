<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../Components/PageHeader.vue';

defineProps({ tabs: Array, cards: Array });
const badge = (s) => ({ aprobado: ['Completado', 'bg-[#5a9e2b] text-white'], en_proceso: ['En proceso', 'bg-[#ffb300] text-white'] })[s] ?? ['Pendiente', 'bg-[#e0e4e8] text-ink'];
</script>

<template>
    <Head title="Inicio" />
    <PageHeader title="Inicio" :tabs="tabs" active="Habilitaciones electrónicas" />

    <div class="mt-[34px] max-w-[1460px]">
        <h2 class="text-[20px] font-bold text-ink">Configuración de documentos electrónicos</h2>
        <p class="mt-1 text-[14px] text-ink-soft">
            Para iniciar a facturar electrónicamente debes realizar el proceso de habilitación de tus documentos, selecciona el que se ajuste a tus necesidades.
        </p>

        <div class="mt-4 flex overflow-hidden rounded border border-[#8cc8f5] bg-[#eaf5fc] text-[13px]">
            <div class="flex w-[46px] items-center justify-center bg-primary text-white"><Icon icon="circle-info" /></div>
            <div class="px-4 py-3 text-ink">
                <p class="font-bold">Recuerda que debes tener a la mano los siguientes datos:</p>
                <p>1. Datos del RUT (Registro único tributario) y del representante legal.</p>
                <p>2. Acceso a tu cuenta de la DIAN.</p>
                <p>3. Identificador del set de pruebas ante la DIAN. <Link href="/configuracion/facturacion-electronica" class="font-bold text-primary">Ver como obtenerlo</Link></p>
            </div>
        </div>

        <div class="mt-4 grid w-full max-w-[790px] grid-cols-1 gap-[18px] md:grid-cols-2">
            <section v-for="c in cards" :key="c.title" class="s-card p-[20px]">
                <div class="flex gap-4">
                    <span class="flex h-[58px] w-[58px] shrink-0 items-center justify-center rounded-[6px] border border-line bg-[#f5f9fd] text-[26px] text-primary"><Icon :icon="c.icon" /></span>
                    <div>
                        <h3 class="text-[16px] font-bold text-ink">{{ c.title }}</h3>
                        <span class="rounded-[3px] px-2 py-[1px] text-[11px] font-bold" :class="badge(c.status)[1]">{{ badge(c.status)[0] }}</span>
                    </div>
                </div>
                <p class="mt-4 text-[14px] text-ink-soft">{{ c.text }}</p>
                <div class="mt-4 flex items-center justify-end gap-4">
                    <Link href="/configuracion/facturacion-electronica" class="text-[13px] font-bold text-primary">¿Necesitas Ayuda?</Link>
                    <Link :href="c.status === 'aprobado' ? c.href : '/configuracion/facturacion-electronica'" class="s-btn s-btn-outline">{{ c.action }}</Link>
                </div>
            </section>
        </div>
    </div>
</template>
