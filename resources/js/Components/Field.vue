<script setup>
defineProps({
    label: { type: String, default: null },
    required: { type: Boolean, default: false },
    error: { type: String, default: null },
});
</script>

<template>
    <label class="block">
        <span v-if="label" class="s-label">{{ label }}<span v-if="required" class="req">*</span></span>
        <slot />
        <p v-if="error" class="s-error">{{ error }}</p>
    </label>
</template>
