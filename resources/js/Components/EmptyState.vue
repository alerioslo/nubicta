<script setup>
defineProps({
    title: { type: String, default: 'No se encontraron resultados para tu búsqueda' },
    text: { type: String, default: 'Modifica tus criterios de búsqueda o filtrado e inténtalo de nuevo' },
    icon: { type: String, default: 'cloud' },
});
</script>

<template>
    <div class="flex flex-col items-center py-16 text-center">
        <Icon :icon="icon" class="mb-6 text-primary/40" :class="icon === 'cloud' ? 'text-[40px]' : 'text-[72px]'" />
        <p class="text-[18px] font-semibold text-ink">{{ title }}</p>
        <p class="mt-1 text-[13px] text-ink-soft">{{ text }}</p>
    </div>
</template>
