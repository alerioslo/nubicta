<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import SideMenu from '../Components/Layout/SideMenu.vue';
import TopBar from '../Components/Layout/TopBar.vue';
import Toast from '../Components/Toast.vue';

const page = usePage();
const nav = computed(() => page.props.nav ?? { sidebar: [], create: {} });
const mobileMenu = ref(false);
onBeforeUnmount(router.on('navigate', () => (mobileMenu.value = false)));
</script>

<template>
    <div class="min-h-screen bg-surface">
        <SideMenu :items="nav.sidebar" :mobile-open="mobileMenu" @close="mobileMenu = false" />
        <div class="md:pl-[92px]">
            <TopBar :create-menu="nav.create" :config-menu="nav.config ?? {}" @toggle-menu="mobileMenu = !mobileMenu" />
            <main class="px-3 pb-10 md:px-[22px]">
                <slot />
            </main>
        </div>
        <Toast />
    </div>
</template>
