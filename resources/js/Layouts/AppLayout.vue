<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import SideMenu from '../Components/Layout/SideMenu.vue';
import TopBar from '../Components/Layout/TopBar.vue';
import Toast from '../Components/Toast.vue';

const page = usePage();
const nav = computed(() => page.props.nav ?? { sidebar: [], create: {} });
</script>

<template>
    <div class="min-h-screen bg-surface">
        <SideMenu :items="nav.sidebar" />
        <div class="pl-[92px]">
            <TopBar :create-menu="nav.create" :config-menu="nav.config ?? {}" />
            <main class="px-[22px] pb-10">
                <slot />
            </main>
        </div>
        <Toast />
    </div>
</template>
