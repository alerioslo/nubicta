<script setup>
import { computed } from 'vue';

const props = defineProps({
    items: { type: Array, required: true },
    donut: { type: Boolean, default: false },
    colors: { type: Array, default: () => ['#ffa630', '#ffcf3d', '#5aa02c', '#1e88e5', '#9c27b0'] },
});

const R = 80, C = 100;
const total = computed(() => props.items.reduce((s, i) => s + Math.max(0, i.value), 0) || 1);
const slices = computed(() => {
    let a = -Math.PI / 2;
    return props.items.map((it, i) => {
        const frac = Math.max(0, it.value) / total.value;
        const start = a;
        a += frac * 2 * Math.PI;
        const large = frac > 0.5 ? 1 : 0;
        const p = (ang) => `${C + R * Math.cos(ang)} ${C + R * Math.sin(ang)}`;
        const d = frac >= 0.9999 ? `M ${C - R} ${C} a ${R} ${R} 0 1 0 ${2 * R} 0 a ${R} ${R} 0 1 0 ${-2 * R} 0` : `M ${C} ${C} L ${p(start)} A ${R} ${R} 0 ${large} 1 ${p(a)} Z`;
        return { d, color: props.colors[i % props.colors.length], label: it.label, value: it.value };
    });
});
const fmt = (v) => v.toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <div>
        <div class="mb-2 flex flex-wrap justify-center gap-x-3 gap-y-1 text-[10px] text-ink-soft">
            <span v-for="s in slices" :key="s.label" class="flex items-center gap-1">
                <span class="inline-block h-[8px] w-[26px]" :style="{ background: s.color }" />{{ s.label }}
            </span>
        </div>
        <svg viewBox="0 0 200 200" class="mx-auto w-[220px]">
            <path v-for="s in slices" :key="s.label" :d="s.d" :fill="s.color" stroke="#fff" stroke-width="1"><title>{{ s.label }}: $ {{ fmt(s.value) }}</title></path>
            <circle v-if="donut" :cx="C" :cy="C" r="44" fill="#fff" />
        </svg>
    </div>
</template>
