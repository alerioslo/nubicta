<script setup>
import { ref, watch } from 'vue';
import { onClickOutside } from '../composables/clickOutside';
import { useFloating, useListKeys } from '../composables/floating';

/* Autocompletar generico: consulta `url?q=` y espera filas con `label` y la llave `valueKey`. */
const props = defineProps({
    url: { type: String, required: true },
    modelValue: { type: [Number, String, null], default: null },
    label: { type: String, default: '' },
    valueKey: { type: String, default: 'id' },
    placeholder: { type: String, default: 'Buscar' },
    inputClass: { type: String, default: 's-input' },
    invalid: { type: Boolean, default: false },
    columns: { type: Array, default: null },
});
const emit = defineEmits(['update:modelValue', 'update:label', 'pick']);

const text = ref(props.label ?? '');
const open = ref(false);
const items = ref([]);
const root = ref(null);
const list = ref(null);
let timer = null;

const { style } = useFloating(root, open, { maxHeight: 300, minWidth: props.columns ? 360 : 220 });

function close() {
    open.value = false;
    text.value = props.label ?? '';
}

onClickOutside([root, list], close);

watch(() => props.label, (v) => (text.value = v ?? ''));

function search() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const sep = props.url.includes('?') ? '&' : '?';
        const res = await fetch(`${props.url}${sep}q=${encodeURIComponent(text.value)}`, { headers: { Accept: 'application/json' } });
        items.value = await res.json();
        open.value = true;
    }, 250);
}

function pick(item) {
    emit('update:modelValue', item[props.valueKey]);
    emit('update:label', item.label);
    emit('pick', item);
    text.value = item.label;
    open.value = false;
}

const { active, onKeydown } = useListKeys(items, open, pick, close);

function clear() {
    emit('update:modelValue', null);
    emit('update:label', '');
    emit('pick', null);
    text.value = '';
}
</script>

<template>
    <div ref="root" class="relative">
        <input v-model="text" :class="[inputClass, 'pr-7', { 'is-invalid': invalid }]" :placeholder="placeholder" autocomplete="off" @focus="search" @input="search" @keydown="onKeydown" />
        <button v-if="modelValue" type="button" class="absolute right-1 top-[7px] text-muted hover:text-danger" @click="clear">
            <Icon icon="xmark" />
        </button>
        <Teleport to="body">
            <ul v-if="open" ref="list" :style="style" class="s-card overflow-auto py-1 shadow-lg">
                <li v-if="columns && items.length" class="grid gap-3 px-3 py-[6px] text-[12px] text-muted" :style="{ gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))` }">
                    <span v-for="c in columns" :key="c[0]">{{ c[1] }}</span>
                </li>
                <li v-for="(i, n) in items" :key="i[valueKey]">
                    <button
                        v-if="columns"
                        type="button"
                        class="grid w-full gap-3 px-3 py-[6px] text-left hover:bg-primary-light"
                        :class="{ 'bg-primary-light': n === active }"
                        :style="{ gridTemplateColumns: `repeat(${columns.length}, minmax(0, 1fr))` }"
                        @mouseenter="active = n"
                        @click="pick(i)"
                    >
                        <span v-for="c in columns" :key="c[0]" class="truncate">{{ i[c[0]] }}</span>
                    </button>
                    <button v-else type="button" class="block w-full px-3 py-[6px] text-left hover:bg-primary-light" :class="{ 'bg-primary-light': n === active }" @mouseenter="active = n" @click="pick(i)">{{ i.label }}</button>
                </li>
                <li v-if="!items.length" class="px-3 py-2 text-muted">Sin resultados</li>
            </ul>
        </Teleport>
    </div>
</template>
