<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../Components/PageHeader.vue';

defineProps({ title: String, active: String, tabs: Array, cards: Array });
</script>

<template>
    <Head :title="title" />
    <PageHeader :title="title" :tabs="tabs" :active="active" />

    <div class="mt-[18px] grid max-w-[1460px] grid-cols-1 items-start gap-[12px] md:grid-cols-2 lg:grid-cols-3">
        <section v-for="c in cards" :key="c.title" class="s-card">
            <h3 class="border-b border-[#edf0f2] px-[28px] py-[16px] text-[18px] font-bold text-ink">{{ c.title }}</h3>
            <ul class="px-[28px] pb-[20px] pt-[14px]">
                <li v-for="i in c.items" :key="i.label" class="flex items-start gap-[8px] py-[3px] text-[13px]">
                    <Icon icon="caret-right" class="mt-[3px] text-[10px] text-primary" />
                    <Link v-if="i.url" :href="i.url" class="text-ink hover:text-primary">{{ i.label }}</Link>
                    <span v-else class="cursor-default text-ink" title="Disponible próximamente">{{ i.label }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
