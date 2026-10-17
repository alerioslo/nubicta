<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const props = defineProps({ items: { type: Array, required: true }, mobileOpen: { type: Boolean, default: false } });
const emit = defineEmits(['close']);

const page = usePage();
const expanded = ref(false);
const wide = computed(() => expanded.value || props.mobileOpen);

const currentPath = computed(() => new URL(page.url, 'http://x').pathname);

function isActive(item) {
    if (item.url === '/') return currentPath.value === '/';
    return currentPath.value === item.url || currentPath.value.startsWith(item.url + '/');
}
function hover(v) {
    if (window.matchMedia('(min-width: 768px)').matches) expanded.value = v;
}
function close() {
    expanded.value = false;
    emit('close');
}
</script>

<template>
    <div v-if="mobileOpen" class="fixed inset-0 z-40 bg-black/40 md:hidden" @click="close" />
    <nav
        class="fixed inset-y-0 left-0 z-40 flex flex-col overflow-y-auto bg-primary text-white shadow-[0_3px_3px_-2px_rgba(0,0,0,.2),0_3px_4px_0_rgba(0,0,0,.14),0_1px_8px_0_rgba(0,0,0,.12)] transition-[width,transform] duration-150 md:translate-x-0"
        :class="[wide ? 'w-[292px]' : 'w-[92px]', mobileOpen ? 'translate-x-0' : '-translate-x-full']"
        @mouseenter="hover(true)"
        @mouseleave="hover(false)"
    >
        <div class="flex h-[56px] shrink-0 items-center justify-end px-[16px] md:hidden">
            <button type="button" class="text-[20px]" aria-label="Cerrar menú" @click="close"><Icon icon="xmark" /></button>
        </div>
        <ul class="flex flex-col gap-[6px] px-[8px] md:mt-[14px]">
            <template v-for="item in items" :key="item.key">
                <li v-if="item.separator" class="mx-2 my-1 border-t border-white/40" />
                <li>
                    <Link
                        :href="item.url"
                        class="flex h-[44px] items-center rounded-[8px] px-[23px] transition-colors"
                        :class="isActive(item) ? 'bg-white/20' : 'hover:bg-white/10'"
                        @click="close"
                    >
                        <span class="flex w-[30px] shrink-0 justify-center text-[20px]">
                            <span v-if="item.icon === 'circle-dollar'" class="flex h-[22px] w-[22px] items-center justify-center rounded-full bg-white text-[12px] text-primary">
                                <Icon icon="dollar-sign" />
                            </span>
                            <Icon v-else :icon="item.icon" />
                        </span>
                        <span v-show="wide" class="ml-[14px] truncate text-[14px] font-semibold">{{ item.label }}</span>
                    </Link>
                </li>
            </template>
        </ul>
    </nav>
</template>
