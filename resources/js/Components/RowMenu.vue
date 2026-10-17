<script setup>
import { ref, toRef } from 'vue';
import { useFloating } from '../composables/floating';
import { onClickOutside } from '../composables/clickOutside';

/* Menu desplegable de una fila: se teletransporta al <body> para que el scroll lateral de la tabla no lo recorte. */
const props = defineProps({
    open: { type: Boolean, default: false },
    minWidth: { type: Number, default: 170 },
    anchorClass: { type: [String, Array, Object], default: 'relative inline-flex' },
});
const emit = defineEmits(['close']);

const anchor = ref(null);
const panel = ref(null);
const { style } = useFloating(anchor, toRef(props, 'open'), { maxHeight: 320, minWidth: props.minWidth, alignRight: true });
onClickOutside([anchor, panel], () => props.open && emit('close'));
</script>

<template>
    <div ref="anchor" :class="anchorClass" @click.stop>
        <slot name="anchor" />
    </div>
    <Teleport to="body">
        <div v-if="open" ref="panel" :style="style" class="overflow-y-auto rounded-[6px] border border-line bg-white py-[6px] text-left shadow-lg" @click="emit('close')">
            <slot />
        </div>
    </Teleport>
</template>
