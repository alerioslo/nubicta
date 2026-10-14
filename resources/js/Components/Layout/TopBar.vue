<script setup>
import { computed, nextTick, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import CreateMenu from './CreateMenu.vue';
import ConfigMenu from './ConfigMenu.vue';
import { onClickOutside } from '../../composables/clickOutside';

defineProps({ createMenu: { type: Object, required: true }, configMenu: { type: Object, default: () => ({}) } });
const showConfig = ref(false);

const page = usePage();
const user = computed(() => page.props.auth?.user);
const company = computed(() => page.props.company);

const showCreate = ref(false);
const showUser = ref(false);
const userMenu = ref(null);
onClickOutside(userMenu, () => (showUser.value = false));

const showHelp = ref(false);
const helpMenu = ref(null);
onClickOutside(helpMenu, () => (showHelp.value = false));
const HELP = [
    ['Centro de ayuda', '/ayuda'], ['Ventas', '/ayuda#ventas'], ['Compras y gastos', '/ayuda#compras'], ['Productos e inventario', '/ayuda#productos'],
    ['Cajas y bancos', '/ayuda#bancos'], ['Nómina', '/ayuda#nomina'], ['Contabilidad', '/ayuda#contabilidad'], ['Reportes', '/ayuda#reportes'],
];

const showBell = ref(false);
const bell = ref(null);
const alerts = ref(null);
onClickOutside(bell, () => (showBell.value = false));
const LEVEL = { danger: 'text-[#c62828]', warn: 'text-[#a86b00]', info: 'text-primary' };
async function loadAlerts() {
    const res = await fetch('/api/notificaciones', { headers: { Accept: 'application/json' } });
    alerts.value = res.ok ? await res.json() : [];
}
function toggleBell() {
    showBell.value = !showBell.value;
    if (showBell.value) loadAlerts();
}
loadAlerts();

const showSearch = ref(false);
const term = ref('');
const results = ref([]);
const searchBox = ref(null);
const active = ref(0);
let timer;
onClickOutside(searchBox, () => (showSearch.value = false));
const searchInput = ref(null);
function openSearch() {
    showSearch.value = true;
    nextTick(() => searchInput.value?.focus());
}
function onTerm() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        if (term.value.trim().length < 2) return (results.value = []);
        const res = await fetch(`/api/buscar-global?q=${encodeURIComponent(term.value.trim())}`, { headers: { Accept: 'application/json' } });
        results.value = res.ok ? await res.json() : [];
        active.value = 0;
    }, 250);
}
function go(r) {
    if (!r) return;
    showSearch.value = false;
    term.value = '';
    results.value = [];
    router.visit(r.href);
}
const grouped = computed(() => results.value.reduce((acc, r, i) => ((acc[r.group] ??= []).push({ ...r, i }), acc), {}));

function logout() {
    router.post('/logout');
}
</script>

<template>
    <header class="sticky top-0 z-30 flex h-[56px] items-center bg-white px-[22px] text-ink">
        <Link href="/" class="mr-[36px] flex items-center">
            <img v-if="company?.logo" :src="company.logo" alt="" class="h-[30px] max-w-[110px] object-contain" />
            <span v-else class="text-[22px] font-extrabold tracking-tight text-primary">{{ (company?.name ?? company?.business_name)?.split(' ')[0] ?? 'ERP' }}</span>
        </Link>
        <span class="truncate text-[15px] font-semibold">{{ user?.name }}</span>

        <div class="ml-auto flex items-center gap-[10px]">
            <div ref="searchBox" class="relative">
                <button v-if="!showSearch" type="button" class="flex h-8 w-8 items-center justify-center text-[17px] text-primary" title="Buscar" @click="openSearch">
                    <Icon icon="magnifying-glass" />
                </button>
                <div v-else class="flex w-[340px] items-center rounded-full border border-primary bg-white">
                    <Icon icon="magnifying-glass" class="ml-3 text-muted" />
                    <input ref="searchInput" v-model="term" class="flex-1 bg-transparent px-2 py-[6px] text-[13px] outline-none" placeholder="Tercero, producto, documento, cuenta o pantalla"
                        @input="onTerm" @keydown.down.prevent="active = Math.min(active + 1, results.length - 1)" @keydown.up.prevent="active = Math.max(active - 1, 0)"
                        @keydown.enter.prevent="go(results[active])" @keydown.esc="showSearch = false" />
                </div>
                <div v-if="showSearch && term.trim().length >= 2" class="s-card absolute right-0 top-[40px] z-50 max-h-[460px] w-[420px] overflow-y-auto py-2 shadow-lg">
                    <template v-for="(list, g) in grouped" :key="g">
                        <div class="px-3 pb-1 pt-2 text-[11px] font-bold uppercase text-muted">{{ g }}</div>
                        <button v-for="r in list" :key="r.i" type="button" class="block w-full px-3 py-[6px] text-left hover:bg-[#e3f1fb]" :class="r.i === active && 'bg-[#e3f1fb]'" @click="go(r)">
                            <div class="truncate text-[13px] text-ink">{{ r.label }}</div>
                            <div v-if="r.sub" class="truncate text-[11px] text-muted">{{ r.sub }}</div>
                        </button>
                    </template>
                    <p v-if="!results.length" class="px-3 py-2 text-[12px] text-muted">Sin resultados.</p>
                </div>
            </div>

            <div class="relative">
                <button type="button" class="s-btn s-btn-primary px-[10px]" @click="showCreate = !showCreate">
                    <Icon icon="plus" class="text-[15px]" /> Crear
                </button>
                <CreateMenu v-if="showCreate" :menu="createMenu" @close="showCreate = false" />
            </div>

            <div ref="helpMenu" class="relative">
                <button type="button" class="s-btn s-btn-outline px-[10px]" @click="showHelp = !showHelp">
                    Ayuda <Icon icon="chevron-down" class="text-[10px]" />
                </button>
                <div v-if="showHelp" class="s-card absolute right-0 top-[42px] z-50 w-[230px] py-2 shadow-lg">
                    <Link v-for="[label, href] in HELP" :key="href" :href="href" class="block px-4 py-[6px] text-[13px] text-ink hover:bg-[#e3f1fb]" :class="href === '/ayuda' && 'font-semibold'" @click="showHelp = false">
                        {{ label }}
                    </Link>
                    <hr class="my-1 border-line" />
                    <a v-if="company?.email" :href="`mailto:${company.email}?subject=Soporte ERP`" class="block px-4 py-[6px] text-[13px] text-ink hover:bg-[#e3f1fb]"><Icon icon="envelope" /> Escribir a soporte</a>
                </div>
            </div>

            <div ref="bell" class="relative">
                <button type="button" class="relative flex h-8 w-8 items-center justify-center text-[18px] text-primary" title="Notificaciones" @click="toggleBell">
                    <Icon icon="bell" />
                    <span v-if="alerts?.length" class="absolute right-0 top-0 flex h-[15px] min-w-[15px] items-center justify-center rounded-full bg-[#e53935] px-[3px] text-[9px] font-bold text-white">{{ alerts.length }}</span>
                </button>
                <div v-if="showBell" class="s-card absolute right-0 top-[42px] z-50 w-[340px] py-2 shadow-lg">
                    <div class="px-4 pb-2 text-[13px] font-bold text-ink">Notificaciones</div>
                    <p v-if="alerts === null" class="px-4 py-2 text-[12px] text-muted">Cargando...</p>
                    <p v-else-if="!alerts.length" class="px-4 py-2 text-[12px] text-muted">No tienes alertas pendientes.</p>
                    <Link v-for="a in alerts" :key="a.title" :href="a.href" class="flex gap-3 px-4 py-[8px] hover:bg-[#f5f9fc]" @click="showBell = false">
                        <Icon :icon="a.icon" class="mt-[2px]" :class="LEVEL[a.level]" />
                        <span><span class="block text-[13px] font-semibold text-ink">{{ a.title }}</span><span class="text-[12px] text-muted">{{ a.text }}</span></span>
                    </Link>
                </div>
            </div>

            <div class="relative">
                <button type="button" class="flex h-8 w-8 items-center justify-center text-[18px] text-primary" title="Configuración" @click="showConfig = !showConfig">
                    <Icon icon="gear" />
                </button>
                <ConfigMenu v-if="showConfig" :menu="configMenu" @close="showConfig = false" />
            </div>

            <div ref="userMenu" class="relative">
                <button
                    type="button"
                    class="flex h-[32px] w-[32px] items-center justify-center rounded-full bg-primary text-[11px] font-extrabold text-white"
                    @click="showUser = !showUser"
                >
                    {{ user?.initials }}
                </button>
                <div v-if="showUser" class="s-card absolute right-0 top-[42px] w-[260px] p-4 shadow-lg">
                    <p class="font-bold text-ink">{{ user?.name }}</p>
                    <p class="text-muted">{{ user?.email }}</p>
                    <p v-if="company" class="mt-2 text-[12px] text-muted">{{ company.name }} · NIT {{ company.nit }}</p>
                    <hr class="my-3 border-line" />
                    <button type="button" class="flex items-center gap-2 text-primary" @click="logout">
                        <Icon icon="right-from-bracket" /> Cerrar sesión
                    </button>
                </div>
            </div>
        </div>
    </header>
</template>
