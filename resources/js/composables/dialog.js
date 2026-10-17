import { reactive } from 'vue';

/* Ventanas de confirmacion, aviso y captura del programa (reemplazan confirm, alert y prompt del navegador). */
export const dialog = reactive({ open: false, kind: 'confirm', message: '', value: '', resolve: null });

function show(kind, message, value = '') {
    dialog.resolve?.(kind === 'confirm' ? false : null);
    return new Promise((resolve) => Object.assign(dialog, { open: true, kind, message: String(message ?? ''), value: value ?? '', resolve }));
}

export function closeDialog(accepted) {
    const { kind, value, resolve } = dialog;
    Object.assign(dialog, { open: false, resolve: null });
    resolve?.(kind === 'confirm' ? accepted : kind === 'prompt' ? (accepted ? value : null) : undefined);
}

export const confirmDialog = (message) => show('confirm', message);
export const alertDialog = (message) => show('alert', message);
export const promptDialog = (message, value = '') => show('prompt', message, value);
