<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({ menu: { type: Object, required: true } });
const emit = defineEmits(['close']);

const categories = computed(() => Object.keys(props.menu));
const active = ref(categories.value[0]);
const column = (c) => (props.menu[active.value] ?? []).filter((g) => g.column === c);
const wide = () => window.matchMedia('(min-width: 768px)').matches;
const opened = ref(wide() ? categories.value[0] : null);
function pick(c) {
    active.value = c;
    opened.value = wide() || opened.value !== c ? c : null;
}
</script>

<template>
    <div class="fixed inset-0 z-40" @click="emit('close')" />
    <div class="s-card fixed inset-x-2 top-[56px] z-50 flex max-h-[calc(100vh-70px)] overflow-hidden shadow-[0_4px_18px_rgba(0,0,0,.18)] md:absolute md:inset-x-auto md:right-[-40px] md:top-[48px] md:w-[980px] md:max-w-[calc(100vw-110px)]">
        <div class="flex w-full flex-col">
            <div class="flex shrink-0 items-center border-b border-line px-[18px] py-[14px] md:px-[26px] md:py-[18px]">
                <Icon icon="gear" class="mr-4 text-[16px] text-primary" />
                <h6 class="text-[16px] font-semibold text-ink">Configuración</h6>
                <button type="button" class="ml-auto text-[18px] text-primary" @click="emit('close')"><Icon icon="xmark" /></button>
            </div>
            <div class="flex min-h-0 overflow-y-auto">
                <ul class="w-full px-[12px] py-[12px] md:w-[262px] md:shrink-0 md:border-r md:border-line md:px-[16px] md:py-[18px]">
                    <li v-for="c in categories" :key="c">
                        <button type="button" class="flex w-full items-center justify-between rounded-[6px] px-[14px] py-[13px] text-left text-[14px]"
                            :class="c === active ? 'bg-primary-light text-primary' : 'text-ink-soft hover:bg-[#f5f7f9]'" @mouseenter="wide() && (active = c)" @click="pick(c)">
                            {{ c }} <Icon icon="chevron-right" class="text-[11px] transition-transform md:rotate-0" :class="opened === c && 'rotate-90'" />
                        </button>
                        <div v-if="opened === c" class="px-[14px] pb-[8px] pt-[6px] md:hidden">
                            <div v-for="g in [...column(0), ...column(1)]" :key="g.title" class="mb-[12px]">
                                <p class="mb-[6px] text-[13px] font-semibold text-ink">{{ g.title }}</p>
                                <ul>
                                    <li v-for="i in g.items" :key="i.label" class="flex items-start gap-[10px] py-[5px] text-[13px]">
                                        <Icon icon="caret-right" class="mt-[3px] text-[11px] text-primary" />
                                        <Link v-if="i.url" :href="i.url" class="text-ink hover:text-primary" @click="emit('close')">{{ i.label }}</Link>
                                        <span v-else class="cursor-default text-ink-soft" title="Disponible próximamente">{{ i.label }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </li>
                </ul>
                <div class="hidden flex-1 px-[24px] py-[20px] md:block">
                    <h4 class="mb-[14px] text-[18px] font-semibold text-ink">{{ active }}</h4>
                    <div class="grid grid-cols-1 gap-x-[24px] lg:grid-cols-2">
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
