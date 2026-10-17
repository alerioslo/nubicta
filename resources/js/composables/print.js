let frame = null;

/* Abre el dialogo de impresion de una pagina imprimible sin salir de la pestaña: la carga en un iframe oculto y ella misma se imprime. */
export function printInPlace(url) {
    frame?.remove();
    frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
    frame.src = url + (url.includes('?') ? '&' : '?') + 'autoprint=1';
    document.body.appendChild(frame);
}

/* Lado de la pagina imprimible: si la cargo printInPlace, espera fuentes, QR e imagenes y lanza la impresion. */
export async function printWhenReady() {
    if (!new URLSearchParams(window.location.search).has('autoprint')) return;
    await new Promise((r) => setTimeout(r, 300));
    await document.fonts?.ready;
    await Promise.all([...document.images].filter((i) => !i.complete).map((i) => new Promise((r) => { i.onload = i.onerror = r; })));
    window.print();
}
