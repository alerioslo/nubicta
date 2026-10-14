<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

defineProps({ sections: { type: Object, required: true } });
</script>

<template>
    <Head title="Configuración" />
    <PageHeader title="Configuración" />
    <div class="mt-[18px] grid grid-cols-3 gap-[18px]">
        <section v-for="(items, name) in sections" :key="name" class="s-card p-[20px]">
            <h2 class="mb-[14px] text-[15px] font-bold text-ink">{{ name }}</h2>
            <ul class="flex flex-col gap-[4px]">
                <li v-for="i in items" :key="i.url">
                    <Link :href="i.url" class="flex items-center gap-3 rounded-[6px] px-2 py-[8px] hover:bg-primary-light">
                        <Icon :icon="i.icon" class="w-[18px] text-primary" />
                        <span class="text-ink">{{ i.label }}</span>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
