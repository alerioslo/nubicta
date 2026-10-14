import { onBeforeUnmount, onMounted } from 'vue';

/* Acepta uno o varios refs: el clic se considera "dentro" si cae en cualquiera de ellos (p. ej. una lista teletransportada). */
export function onClickOutside(targetRefs, handler) {
    const refs = Array.isArray(targetRefs) ? targetRefs : [targetRefs];
    const listener = (event) => {
        const els = refs.map((r) => r.value).filter(Boolean);
        if (els.length && !els.some((el) => el.contains(event.target))) {
            handler(event);
        }
    };
    onMounted(() => document.addEventListener('mousedown', listener));
    onBeforeUnmount(() => document.removeEventListener('mousedown', listener));
}
