<script setup>
import { computed } from 'vue';

/* series: { nombre: [valores por etiqueta] } */
const props = defineProps({
    labels: { type: Array, required: true },
    series: { type: Object, required: true },
    colors: { type: Array, default: () => [['#eef8e6', '#9fd17a'], ['#e0f2fe', '#8cc8f5'], ['#f6dcf7', '#d58ad8']] },
});

const W = 440, H = 190, L = 70, B = 24;
const names = computed(() => Object.keys(props.series));
const max = computed(() => {
    const m = Math.max(1, ...Object.values(props.series).flat());
    const step = 10 ** Math.floor(Math.log10(m));
    return Math.ceil(m / step) * step;
});
const ticks = computed(() => Array.from({ length: 7 }, (_, i) => (max.value / 6) * i));
const slot = computed(() => (W - L) / Math.max(1, props.labels.length));
const barW = computed(() => Math.min(26, (slot.value * 0.7) / Math.max(1, names.value.length)));
const y = (v) => H - B - ((H - B - 6) * v) / max.value;
const fmt = (v) => Math.round(v).toLocaleString('es-CO');
</script>

<template>
    <div>
        <div class="mb-1 flex flex-wrap justify-center gap-3 text-[10px] text-ink-soft">
            <span v-for="(n, i) in names" :key="n" class="flex items-center gap-1">
                <span class="inline-block h-[8px] w-[26px] border" :style="{ background: colors[i][0], borderColor: colors[i][1] }" />{{ n }}
            </span>
        </div>
        <svg :viewBox="`0 0 ${W} ${H}`" class="w-full">
            <g v-for="t in ticks" :key="t">
                <line :x1="L" :x2="W" :y1="y(t)" :y2="y(t)" stroke="#e6e9ec" />
                <text :x="L - 6" :y="y(t) + 3" text-anchor="end" font-size="8" fill="#6c757d">{{ fmt(t) }}</text>
            </g>
            <g v-for="(lab, i) in labels" :key="lab">
                <line :x1="L + slot * i" :x2="L + slot * i" :y1="6" :y2="H - B" stroke="#f0f2f4" />
                <rect v-for="(n, j) in names" :key="n" :x="L + slot * i + (slot - barW * names.length) / 2 + barW * j" :y="y(series[n][i])"
                    :width="barW - 2" :height="H - B - y(series[n][i])" :fill="colors[j][0]" :stroke="colors[j][1]">
                    <title>{{ n }}: $ {{ fmt(series[n][i]) }}</title>
                </rect>
                <text :x="L + slot * i + slot / 2" :y="H - 8" text-anchor="middle" font-size="8" fill="#6c757d">{{ lab }}</text>
            </g>
        </svg>
    </div>
</template>
