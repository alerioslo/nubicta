<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

defineProps({ items: { type: Array, required: true } });

const page = usePage();
const expanded = ref(false);

const currentPath = computed(() => new URL(page.url, 'http://x').pathname);

function isActive(item) {
    if (item.url === '/') return currentPath.value === '/';
    return currentPath.value === item.url || currentPath.value.startsWith(item.url + '/');
}
</script>

<template>
    <nav
        class="fixed inset-y-0 left-0 z-40 flex flex-col bg-primary text-white shadow-[0_3px_3px_-2px_rgba(0,0,0,.2),0_3px_4px_0_rgba(0,0,0,.14),0_1px_8px_0_rgba(0,0,0,.12)] transition-[width] duration-150"
        :class="expanded ? 'w-[292px]' : 'w-[92px]'"
        @mouseenter="expanded = true"
        @mouseleave="expanded = false"
    >
        <ul class="mt-[14px] flex flex-col gap-[6px] px-[8px]">
            <template v-for="item in items" :key="item.key">
                <li v-if="item.separator" class="mx-2 my-1 border-t border-white/40" />
                <li>
                    <Link
                        :href="item.url"
                        class="flex h-[44px] items-center rounded-[8px] px-[23px] transition-colors"
                        :class="isActive(item) ? 'bg-white/20' : 'hover:bg-white/10'"
                        @click="expanded = false"
                    >
                        <span class="flex w-[30px] shrink-0 justify-center text-[20px]">
                            <span v-if="item.icon === 'circle-dollar'" class="flex h-[22px] w-[22px] items-center justify-center rounded-full bg-white text-[12px] text-primary">
                                <Icon icon="dollar-sign" />
                            </span>
                            <Icon v-else :icon="item.icon" />
                        </span>
                        <span v-show="expanded" class="ml-[14px] truncate text-[14px] font-semibold">{{ item.label }}</span>
                    </Link>
                </li>
            </template>
        </ul>
    </nav>
</template>
