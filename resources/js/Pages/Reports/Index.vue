<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({ catalog: { type: Array, required: true } });

const q = ref('');
const closed = reactive({});
const favKey = 'friigo.report.favorites';
const keyOf = (r) => (r.url?.match(/^\/reportes\/([^?/]+)$/)?.[1] ?? r.url);
const all = props.catalog.filter((s) => s.section !== 'Favoritos').flatMap((s) => s.groups.flatMap((g) => g.reports)).filter((r) => r.url);
const defaults = (props.catalog.find((s) => s.section === 'Favoritos')?.groups ?? []).flatMap((g) => g.reports).filter((r) => r.url);
const favorites = ref(JSON.parse(localStorage.getItem(favKey) ?? 'null') ?? defaults.map(keyOf));
function toggleFav(r) {
    const k = keyOf(r);
    favorites.value = favorites.value.includes(k) ? favorites.value.filter((x) => x !== k) : [...favorites.value, k];
    localStorage.setItem(favKey, JSON.stringify(favorites.value));
}
const favReports = computed(() => favorites.value.map((k) => [...defaults, ...all].find((r) => keyOf(r) === k)).filter(Boolean));
const catalog = computed(() => props.catalog.map((s) => (s.section === 'Favoritos' ? { ...s, groups: [{ name: 'Favoritos', reports: favReports.value }] } : s)));
const norm = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

const sections = computed(() => {
    const term = norm(q.value.trim());
    return catalog.value
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
    <PageHeader title="Reportes">
        <template #actions><div class="flex h-[32px] w-[310px]">
            <input v-model="q" class="s-input h-full rounded-r-none" placeholder="Buscar por:" />
            <span class="flex w-[32px] shrink-0 items-center justify-center rounded-r-[4px] bg-primary text-white"><Icon icon="magnifying-glass" /></span>
        </div></template>
    </PageHeader>

    <section v-for="s in sections" :key="s.section" class="s-card mt-[14px]">
        <button type="button" class="flex w-full items-center gap-3 px-[22px] py-[16px] text-left" @click="closed[s.section] = !closed[s.section]">
            <Icon :icon="closed[s.section] && !q ? 'chevron-right' : 'chevron-down'" class="text-muted" />
            <span class="text-[17px] font-bold text-ink">{{ s.section }}</span>
        </button>
        <div v-if="!closed[s.section] || q" class="grid grid-cols-2 gap-x-[60px] border-t border-line px-[22px] pb-[22px] pt-[16px]">
            <div v-for="(col, ci) in columns(s.groups)" :key="ci" class="flex flex-col gap-[18px]">
                <div v-for="g in col" :key="g.name">
                    <h3 v-if="g.name !== s.section || s.groups.length > 1" class="mb-[8px] text-[15px] font-bold text-ink">{{ g.name }}</h3>
                    <ul class="flex flex-col">
                        <li v-for="r in g.reports" :key="r.name" class="flex items-center gap-[14px] border-b border-[#e6e9ec] py-[10px] text-[13.5px]">
                            <Link v-if="r.url" :href="r.url" class="text-ink hover:text-primary">{{ r.name }}</Link>
                            <span v-else class="text-muted">{{ r.name }}</span>
                            <span v-if="!r.url" class="s-badge s-badge-off ml-auto">Próximamente</span>
                            <template v-else>
                                <span class="ml-auto flex h-[15px] w-[15px] items-center justify-center rounded-full bg-primary text-[10px] font-bold text-white" :title="r.name">?</span>
                                <button type="button" class="text-[14px]" :class="favorites.includes(keyOf(r)) ? 'text-primary' : 'text-[#9aa3ab]'" :title="favorites.includes(keyOf(r)) ? 'Quitar de favoritos' : 'Agregar a favoritos'" @click="toggleFav(r)"><Icon icon="star" /></button>
                            </template>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
</template>
