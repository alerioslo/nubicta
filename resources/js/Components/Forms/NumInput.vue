<script setup>
import { computed, ref } from 'vue';

/* Campo numerico de Siigo: muestra 0.00 con separador de miles y edita el valor plano al enfocar. */
const props = defineProps({
    modelValue: { type: [Number, String, null], default: 0 },
    decimals: { type: Number, default: 2 },
});
const emit = defineEmits(['update:modelValue']);
const focused = ref(false);

const shown = computed(() => {
    if (focused.value) return props.modelValue ?? '';
    const n = Number(props.modelValue || 0);
    return n.toLocaleString('en-US', { minimumFractionDigits: props.decimals, maximumFractionDigits: Math.max(props.decimals, 6) });
});

function input(e) {
    const raw = e.target.value.replace(/,/g, '');
    emit('update:modelValue', raw === '' || raw === '-' ? 0 : Number(raw));
}
</script>

<template>
    <input :value="shown" inputmode="decimal" class="doc-input doc-num" @focus="focused = true" @blur="focused = false" @input="input" />
</template>
