<script setup>
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';

/* Paginador estilo Siigo: "Items por pagina", "1 a 20 de N", flechas. Recibe un paginador de Laravel. */
const props = defineProps({ paginator: { type: Object, required: true } });

const p = computed(() => props.paginator);

function go(page, perPage = p.value.per_page) {
    if (page < 1 || page > p.value.last_page) return;
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    url.searchParams.set('per_page', perPage);
    router.get(url.pathname + url.search, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <div class="flex items-center justify-end gap-6 border-t border-[#edf0f2] px-5 py-3 text-[13px]">
        <span class="flex items-center gap-2">
            Items por página:
            <select class="s-input h-[30px] w-[64px] px-2" :value="p.per_page" @change="go(1, $event.target.value)">
                <option v-for="n in [10, 20, 50, 100]" :key="n" :value="n">{{ n }}</option>
            </select>
        </span>
        <span><b>{{ p.from ?? 0 }}</b> a <b>{{ p.to ?? 0 }}</b> de <b>{{ p.total }}</b></span>
        <span class="flex items-center gap-3 text-muted">
            <button type="button" :disabled="p.current_page <= 1" class="disabled:opacity-40" @click="go(1)"><Icon icon="angles-left" /></button>
            <button type="button" :disabled="p.current_page <= 1" class="disabled:opacity-40" @click="go(p.current_page - 1)"><Icon icon="chevron-left" /></button>
            <span class="text-ink">Página <b>{{ p.current_page }}</b> de <b>{{ p.last_page }}</b></span>
            <button type="button" :disabled="p.current_page >= p.last_page" class="disabled:opacity-40" @click="go(p.current_page + 1)"><Icon icon="chevron-right" /></button>
            <button type="button" :disabled="p.current_page >= p.last_page" class="disabled:opacity-40" @click="go(p.last_page)"><Icon icon="angles-right" /></button>
        </span>
    </div>
</template>
