<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { onClickOutside } from '../../composables/clickOutside';

defineProps({ menu: { type: Object, required: true } });
const emit = defineEmits(['close']);

const panel = ref(null);
onClickOutside(panel, () => emit('close'));
</script>

<template>
    <div
        ref="panel"
        class="s-card absolute right-[-120px] top-[42px] z-50 flex gap-[40px] px-[28px] pb-[22px] pt-[20px] shadow-[0_4px_18px_rgba(0,0,0,.14)]"
    >
        <button type="button" class="absolute right-[14px] top-[10px] text-[15px] text-primary" @click="emit('close')">
            <Icon icon="xmark" />
        </button>
        <div v-for="(items, column) in menu" :key="column" class="w-[150px]">
            <h4 class="mb-[12px] text-[14px] font-bold text-ink">{{ column }}</h4>
            <ul class="flex flex-col gap-[9px]">
                <li v-for="item in items" :key="item.url">
                    <Link :href="item.url" class="text-[13px] text-ink-soft hover:text-primary" @click="emit('close')">
                        {{ item.label }}
                    </Link>
                </li>
            </ul>
        </div>
    </div>
</template>
