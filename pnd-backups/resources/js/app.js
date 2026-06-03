import Alpine from 'alpinejs';
window.Alpine = Alpine;
Alpine.start();

/**
 * Inicia una descarga de archivo (GET) y muestra un estado loading en el trigger.
 * Usa la técnica de cookie polling para detectar cuándo el servidor terminó de enviar.
 *
 * @param {object} opts
 * @param {string}       opts.url         URL de descarga
 * @param {HTMLElement}  opts.trigger     Elemento que se deshabilita durante la espera
 * @param {string}       opts.loadingText Texto a mostrar mientras se procesa
 * @param {string}       opts.idleText    Texto original (restaurado al terminar)
 * @param {Function}    [opts.onDone]     Callback al completar (opcional)
 */
window.downloadWithFeedback = function ({ url, trigger, loadingText, idleText, onDone }) {
    const TOKEN = Math.random().toString(36).slice(2, 11);
    const COOKIE_NAME = 'dl_ready';
    const sep = url.includes('?') ? '&' : '?';
    const fullUrl = url + sep + 'dl=' + TOKEN;

    trigger.disabled = true;
    trigger.innerHTML =
        '<span style="display:inline-flex;align-items:center;gap:6px">' +
        '<svg style="animation:spin 1s linear infinite;width:12px;height:12px" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">' +
        '<circle style="opacity:.25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>' +
        '<path style="opacity:.75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>' +
        '</svg>' +
        loadingText +
        '</span>';

    window.location.href = fullUrl;

    const INTERVAL = 500;
    const MAX_WAIT = 10 * 60 * 1000;
    let elapsed = 0;

    const timer = setInterval(function () {
        elapsed += INTERVAL;
        const cookies = document.cookie.split(';').map(function (c) { return c.trim(); });
        const match = cookies.find(function (c) { return c.startsWith(COOKIE_NAME + '='); });

        if (match && match.split('=')[1] === TOKEN) {
            document.cookie = COOKIE_NAME + '=; Max-Age=0; path=/';
            clearInterval(timer);
            trigger.disabled = false;
            trigger.innerHTML = idleText;
            if (onDone) onDone();
        } else if (elapsed >= MAX_WAIT) {
            clearInterval(timer);
            trigger.disabled = false;
            trigger.innerHTML = idleText;
        }
    }, INTERVAL);
};

// Animación spin para el spinner JS (no depende de Tailwind en el contexto inline)
(function () {
    if (document.getElementById('dl-spin-style')) return;
    var s = document.createElement('style');
    s.id = 'dl-spin-style';
    s.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(s);
}());
