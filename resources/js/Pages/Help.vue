<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

/* Centro de ayuda: guias por modulo con buscador. */
const props = defineProps({ sections: Array });
const q = ref('');
const norm = (s) => (s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const filtered = computed(() => props.sections
    .map((s) => ({ ...s, guides: s.guides.filter((g) => !q.value || norm(g[0] + ' ' + g[1]).includes(norm(q.value))) }))
    .filter((s) => s.guides.length));
</script>

<template>
    <Head title="Centro de ayuda" />
    <div class="px-[22px] pt-[24px]">
        <h1 class="text-[24px] font-semibold text-primary">Centro de ayuda</h1>
        <p class="text-[13px] text-muted">Guías de cada proceso del ERP.</p>
        <div class="mt-4 flex flex-wrap items-center gap-3">
            <span class="relative w-[360px]">
                <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
                <input v-model="q" class="s-input pl-9" placeholder="¿Qué necesitas hacer?" />
            </span>
            <a v-for="s in sections" :key="s.id" :href="`#${s.id}`" class="rounded-full bg-[#e3f1fb] px-3 py-[5px] text-[12px] text-primary hover:underline">{{ s.title }}</a>
        </div>
    </div>

    <div class="grid gap-[14px] p-[10px] lg:grid-cols-2">
        <section v-for="s in filtered" :id="s.id" :key="s.id" class="s-card scroll-mt-[70px] p-[18px]">
            <h2 class="mb-3 flex items-center gap-2 text-[16px] font-bold text-ink"><Icon :icon="s.icon" class="text-primary" /> {{ s.title }}</h2>
            <div v-for="g in s.guides" :key="g[0]" class="border-b border-line py-[10px] last:border-0">
                <div class="flex items-center gap-2">
                    <h3 class="text-[13.5px] font-semibold text-ink">{{ g[0] }}</h3>
                    <Link v-if="g[2]" :href="g[2]" class="ml-auto whitespace-nowrap text-[12px] font-semibold text-primary hover:underline">Ir <Icon icon="chevron-right" class="text-[10px]" /></Link>
                </div>
                <p class="mt-1 text-[12.5px] leading-[1.5] text-ink-soft">{{ g[1] }}</p>
            </div>
        </section>
        <p v-if="!filtered.length" class="p-6 text-muted">No hay guías que coincidan con la búsqueda.</p>
    </div>
</template>
