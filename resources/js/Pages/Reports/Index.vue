<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({ catalog: { type: Array, required: true } });

const q = ref('');
const closed = reactive({});
const norm = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

const sections = computed(() => {
    const term = norm(q.value.trim());
    return props.catalog
        .map((s) => ({
            ...s,
            groups: s.groups
                .map((g) => ({ ...g, reports: term ? g.reports.filter((r) => norm(r.name).includes(term)) : g.reports }))
                .filter((g) => g.reports.length),
        }))
        .filter((s) => s.groups.length);
});
const columns = (groups) => [groups.filter((_, i) => i % 2 === 0), groups.filter((_, i) => i % 2 === 1)];
</script>

<template>
    <Head title="Reportes" />
    <PageHeader title="Reportes" />

    <section class="s-card mt-[18px] p-[18px]">
        <span class="s-label font-normal text-muted">Buscar por:</span>
        <div class="relative w-[420px]">
            <Icon icon="magnifying-glass" class="absolute left-3 top-[10px] text-muted" />
            <input v-model="q" class="s-input pl-9" placeholder="Nombre del reporte" />
        </div>
    </section>

    <section v-for="s in sections" :key="s.section" class="s-card mt-[14px]">
        <button type="button" class="flex w-full items-center gap-3 px-[22px] py-[16px] text-left" @click="closed[s.section] = !closed[s.section]">
            <Icon :icon="closed[s.section] && !q ? 'chevron-right' : 'chevron-down'" class="text-muted" />
            <span class="text-[17px] font-bold text-ink">{{ s.section }}</span>
        </button>
        <div v-if="!closed[s.section] || q" class="grid grid-cols-2 gap-x-[60px] border-t border-line px-[56px] pb-[22px] pt-[16px]">
            <div v-for="(col, ci) in columns(s.groups)" :key="ci" class="flex flex-col gap-[18px]">
                <div v-for="g in col" :key="g.name">
                    <h3 v-if="g.name !== s.section || s.groups.length > 1" class="mb-[8px] text-[15px] font-bold text-ink">{{ g.name }}</h3>
                    <ul class="flex flex-col">
                        <li v-for="r in g.reports" :key="r.name" class="flex items-center gap-2 border-b border-[#f0f2f4] py-[9px]">
                            <Link v-if="r.url" :href="r.url" class="text-primary hover:underline">{{ r.name }}</Link>
                            <span v-else class="text-ink">{{ r.name }}</span>
                            <span v-if="!r.url" class="s-badge s-badge-off ml-auto">Próximamente</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
</template>
