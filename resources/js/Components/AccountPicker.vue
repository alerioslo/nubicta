<script setup>
import { ref, watch } from 'vue';
import { onClickOutside } from '../composables/clickOutside';
import { useFloating, useListKeys } from '../composables/floating';

/* Selector de cuenta contable con busqueda por codigo o nombre (solo cuentas transaccionales). */
const props = defineProps({
    modelValue: { type: [Number, String, null], default: null },
    label: { type: String, default: '' },
    invalid: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'update:label']);

const text = ref(props.label ?? '');
const open = ref(false);
const items = ref([]);
const root = ref(null);
const list = ref(null);
let timer = null;

const { style } = useFloating(root, open, { maxHeight: 260, minWidth: 260 });

function close() {
    open.value = false;
    text.value = props.label ?? '';
}

onClickOutside([root, list], close);

watch(() => props.label, (v) => (text.value = v ?? ''));

function search() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const res = await fetch(`/api/cuentas?q=${encodeURIComponent(text.value)}`, { headers: { Accept: 'application/json' } });
        items.value = await res.json();
        open.value = true;
    }, 250);
}

function pick(item) {
    emit('update:modelValue', item.id);
    emit('update:label', item.label);
    text.value = item.label;
    open.value = false;
}

const { active, onKeydown } = useListKeys(items, open, pick, close);

function clear() {
    emit('update:modelValue', null);
    emit('update:label', '');
    text.value = '';
}
</script>

<template>
    <div ref="root" class="relative">
        <input
            v-model="text"
            class="s-input pr-8"
            :class="{ 'is-invalid': invalid }"
            placeholder="Buscar por código o nombre"
            autocomplete="off"
            @focus="search"
            @input="search"
            @keydown="onKeydown"
        />
        <button v-if="modelValue" type="button" class="absolute right-2 top-[9px] text-muted hover:text-danger" @click="clear">
            <Icon icon="xmark" />
        </button>
        <Teleport to="body">
            <ul v-if="open" ref="list" :style="style" class="s-card overflow-auto py-1 shadow-lg">
                <li v-for="(i, n) in items" :key="i.id">
                    <button type="button" class="block w-full px-3 py-[6px] text-left hover:bg-primary-light" :class="{ 'bg-primary-light': n === active }" @mouseenter="active = n" @click="pick(i)">{{ i.label }}</button>
                </li>
                <li v-if="!items.length" class="px-3 py-2 text-muted">Sin resultados</li>
            </ul>
        </Teleport>
    </div>
</template>
