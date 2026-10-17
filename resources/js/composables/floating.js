import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

/*
 * Posiciona una lista desplegable teletransportada al <body> junto a su campo ancla.
 * Se abre hacia arriba cuando no cabe debajo y sigue al ancla al hacer scroll en cualquier contenedor.
 */
export function useFloating(anchorRef, openRef, { maxHeight = 260, minWidth = 0, alignRight = false } = {}) {
    const style = ref({});

    function update() {
        const el = anchorRef.value;
        if (!el) return;
        const r = el.getBoundingClientRect();
        const below = window.innerHeight - r.bottom - 8;
        const above = r.top - 8;
        const up = below < Math.min(maxHeight, 160) && above > below;
        const width = Math.max(r.width, minWidth);
        const left = Math.min(r.left, window.innerWidth - width - 8);
        style.value = {
            position: 'fixed',
            ...(alignRight
                ? { ...(r.right < minWidth + 4 ? { left: '4px' } : { right: `${Math.max(window.innerWidth - r.right, 4)}px` }), minWidth: `${minWidth}px`, maxWidth: `${window.innerWidth - 8}px` }
                : { left: `${Math.max(left, 4)}px`, width: `${width}px` }),
            maxHeight: `${Math.max(Math.min(maxHeight, up ? above : below), 120)}px`,
            zIndex: 1000,
            ...(up ? { bottom: `${window.innerHeight - r.top + 1}px` } : { top: `${r.bottom + 1}px` }),
        };
    }

    const listen = () => {
        window.addEventListener('scroll', update, true);
        window.addEventListener('resize', update);
    };
    const unlisten = () => {
        window.removeEventListener('scroll', update, true);
        window.removeEventListener('resize', update);
    };

    watch(openRef, (v) => {
        if (v) {
            nextTick(update);
            listen();
        } else {
            unlisten();
        }
    });
    onBeforeUnmount(unlisten);

    return { style, update };
}

/* Navegacion con flechas / Enter / Escape sobre una lista de resultados. */
export function useListKeys(itemsRef, openRef, onPick, onClose) {
    const active = ref(-1);
    watch(itemsRef, () => (active.value = itemsRef.value.length ? 0 : -1));

    function onKeydown(e) {
        if (!openRef.value) return;
        const n = itemsRef.value.length;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            active.value = n ? (active.value + 1) % n : -1;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            active.value = n ? (active.value - 1 + n) % n : -1;
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (active.value >= 0 && itemsRef.value[active.value]) onPick(itemsRef.value[active.value]);
        } else if (e.key === 'Escape' || e.key === 'Tab') {
            onClose();
        }
    }

    return { active, onKeydown };
}
