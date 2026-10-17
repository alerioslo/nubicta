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
        class="s-card fixed inset-x-2 top-[56px] z-50 grid max-h-[calc(100vh-70px)] grid-cols-2 gap-x-[20px] gap-y-[18px] overflow-y-auto px-[18px] pb-[18px] pt-[20px] shadow-[0_4px_18px_rgba(0,0,0,.14)] md:absolute md:inset-x-auto md:right-[-120px] md:top-[42px] md:flex md:max-w-[calc(100vw-110px)] md:flex-wrap md:gap-[40px] md:px-[28px] md:pb-[22px]"
    >
        <button type="button" class="absolute right-[14px] top-[10px] text-[15px] text-primary" @click="emit('close')">
            <Icon icon="xmark" />
        </button>
        <div v-for="(items, column) in menu" :key="column" class="min-w-0 md:w-[150px]">
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
