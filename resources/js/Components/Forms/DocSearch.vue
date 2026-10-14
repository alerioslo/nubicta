<script setup>
import { ref, watch } from 'vue';
import { onClickOutside } from '../../composables/clickOutside';
import { useFloating, useListKeys } from '../../composables/floating';

/*
 * Buscador compacto de los documentos de Siigo (input con lupa). La URL debe devolver filas con `id` y `label`;
 * con `options` filtra una lista local. `columns` ([clave, título]) pinta la lista como la tabla de Siigo y
 * `createUrl` agrega "+ Crear nuevo" / "Ver más". `display` es el campo que queda escrito al elegir.
 */
const props = defineProps({
    url: { type: String, default: '' },
    options: { type: Array, default: null },
    modelValue: { type: [Number, String, null], default: null },
    label: { type: String, default: '' },
    placeholder: { type: String, default: 'Buscar' },
    invalid: { type: Boolean, default: false },
    inputClass: { type: String, default: 'doc-input' },
    columns: { type: Array, default: null },
    createUrl: { type: String, default: '' },
    display: { type: String, default: 'label' },
    minWidth: { type: Number, default: 260 },
});
const emit = defineEmits(['update:modelValue', 'update:label', 'pick']);

const text = ref(props.label ?? '');
const open = ref(false);
const items = ref([]);
const root = ref(null);
const list = ref(null);
const limit = ref(10);
let timer = null;

const { style } = useFloating(root, open, { maxHeight: 260, minWidth: props.columns ? Math.max(props.minWidth, 420) : props.minWidth });

function close() {
    open.value = false;
    limit.value = 10;
    text.value = props.label ?? '';
}

onClickOutside([root, list], close);

watch(() => props.label, (v) => (text.value = v ?? ''));

const norm = (s) => String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

function search() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const term = text.value === props.label ? '' : text.value;
        if (props.options) {
            items.value = props.options.filter((o) => !term || norm(Object.values(o).join(' ')).includes(norm(term))).slice(0, limit.value);
        } else {
            const sep = props.url.includes('?') ? '&' : '?';
            const res = await fetch(`${props.url}${sep}q=${encodeURIComponent(term)}&limite=${limit.value}`, { headers: { Accept: 'application/json' } });
            items.value = await res.json();
        }
        open.value = true;
    }, props.options ? 0 : 250);
}

function more() {
    limit.value += 20;
    search();
}

function pick(item) {
    const shown = String(item[props.display] ?? item.label);
    emit('update:modelValue', item.id);
    emit('update:label', shown);
    emit('pick', item);
    text.value = shown;
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
        <input v-model="text" :class="[inputClass, 'pr-6', { 'is-invalid': invalid }]" :placeholder="placeholder" autocomplete="off" @focus="search" @input="search" @keydown="onKeydown" />
        <button v-if="modelValue" type="button" class="absolute right-[6px] top-1/2 -translate-y-1/2 text-[11px] text-[#333] hover:text-danger" @click="clear">
            <Icon icon="xmark" />
        </button>
        <Icon v-else icon="magnifying-glass" class="pointer-events-none absolute right-[7px] top-1/2 -translate-y-1/2 text-[11px] text-[#333]" />
        <Teleport to="body">
            <div v-if="open" ref="list" data-docsearch-list :style="style" class="flex flex-col border border-[#ccc] bg-white text-[11px] shadow-lg">
                <div class="min-h-0 flex-1 overflow-auto">
                    <table v-if="columns" class="w-full">
                        <thead class="sticky top-0 bg-[#f2f2f2] text-[10.5px] font-semibold text-[#333]">
                            <tr><th v-for="[k, title] in columns" :key="k" class="px-[6px] py-[2px] text-left">{{ title }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(i, n) in items" :key="i.id" class="cursor-pointer text-[#555]" :class="n === active ? 'bg-[#e6e6e6]' : 'hover:bg-[#f2f2f2]'" @mouseenter="active = n" @click="pick(i)">
                                <td v-for="[k] in columns" :key="k" class="whitespace-nowrap px-[6px] py-[2px]">{{ i[k] }}</td>
                            </tr>
                            <tr v-if="!items.length"><td :colspan="columns.length" class="px-[6px] py-[6px] text-muted">No se encontraron resultados</td></tr>
                        </tbody>
                    </table>
                    <ul v-else class="py-[2px] text-[12px]">
                        <li v-for="(i, n) in items" :key="i.id">
                            <button type="button" class="block w-full px-[8px] py-[4px] text-left hover:bg-primary-light" :class="{ 'bg-primary-light': n === active }" @mouseenter="active = n" @click="pick(i)">{{ i.label }}</button>
                        </li>
                        <li v-if="!items.length" class="px-[8px] py-[6px] text-muted">Sin resultados</li>
                    </ul>
                </div>
                <div v-if="createUrl" class="flex items-center justify-between bg-[#64b5f6] px-[10px] py-[3px] text-[11px] text-white">
                    <a :href="createUrl" target="_blank" class="hover:underline">+ Crear nuevo</a>
                    <button v-if="items.length >= limit" type="button" class="hover:underline" @click="more">Ver más</button>
                </div>
            </div>
        </Teleport>
    </div>
</template>
