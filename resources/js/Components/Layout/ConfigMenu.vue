<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({ menu: { type: Object, required: true } });
const emit = defineEmits(['close']);

const categories = computed(() => Object.keys(props.menu));
const active = ref(categories.value[0]);
const column = (c) => (props.menu[active.value] ?? []).filter((g) => g.column === c);
</script>

<template>
    <div class="fixed inset-0 z-40" @click="emit('close')" />
    <div class="s-card absolute right-[-40px] top-[48px] z-50 flex w-[980px] overflow-hidden shadow-[0_4px_18px_rgba(0,0,0,.18)]">
        <div class="w-full">
            <div class="flex items-center border-b border-line px-[26px] py-[18px]">
                <Icon icon="gear" class="mr-4 text-[16px] text-primary" />
                <h6 class="text-[16px] font-semibold text-ink">Configuración</h6>
                <button type="button" class="ml-auto text-[18px] text-primary" @click="emit('close')"><Icon icon="xmark" /></button>
            </div>
            <div class="flex">
                <ul class="w-[262px] border-r border-line px-[16px] py-[18px]">
                    <li v-for="c in categories" :key="c">
                        <button type="button" class="flex w-full items-center justify-between rounded-[6px] px-[14px] py-[13px] text-left text-[14px]"
                            :class="c === active ? 'bg-primary-light text-primary' : 'text-ink-soft hover:bg-[#f5f7f9]'" @mouseenter="active = c" @click="active = c">
                            {{ c }} <Icon icon="chevron-right" class="text-[11px]" />
                        </button>
                    </li>
                </ul>
                <div class="flex-1 px-[24px] py-[20px]">
                    <h4 class="mb-[14px] text-[18px] font-semibold text-ink">{{ active }}</h4>
                    <div class="grid grid-cols-2 gap-x-[24px]">
                        <div v-for="c in [0, 1]" :key="c">
                            <div v-for="g in column(c)" :key="g.title" class="mb-[16px]">
                                <p class="mb-[8px] text-[14px] font-semibold text-ink">{{ g.title }}</p>
                                <ul>
                                    <li v-for="i in g.items" :key="i.label" class="flex items-start gap-[10px] py-[5px] text-[13px]">
                                        <Icon icon="caret-right" class="mt-[3px] text-[11px] text-primary" />
                                        <Link v-if="i.url" :href="i.url" class="text-ink hover:text-primary" @click="emit('close')">{{ i.label }}</Link>
                                        <span v-else class="cursor-default text-ink-soft" title="Disponible próximamente">{{ i.label }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
