<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../Components/PageHeader.vue';

defineProps({ shortcuts: { type: Array, required: true }, tabs: { type: Array, required: true } });
</script>

<template>
    <Head title="Inicio" />
    <PageHeader title="Inicio" :tabs="tabs" active="Accesos directos" />

    <section class="s-card mt-[34px]">
        <h2 class="border-b border-[#edf0f2] px-[28px] py-[16px] text-[18px] font-bold text-ink">
            Te damos la bienvenida, ¿Qué deseas hacer?
        </h2>
        <div class="grid grid-cols-5 gap-y-[38px] px-[28px] pb-[48px] pt-[18px]">
            <Link v-for="s in shortcuts" :key="s.url" :href="s.url" class="group flex flex-col items-center text-center">
                <span
                    class="flex h-[64px] w-[64px] items-center justify-center rounded-full border border-[#dfe6ec] bg-white text-[24px] text-primary transition group-hover:border-primary group-hover:bg-primary-light"
                >
                    <Icon :icon="s.icon" />
                </span>
                <span class="mt-[12px] max-w-[200px] text-[13px] font-semibold text-ink">{{ s.label }}</span>
            </Link>
        </div>
    </section>
</template>
