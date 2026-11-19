const swaggerTranslations = new Map([
  ['Available authorizations', 'Autorizaciones disponibles'],
  ['Scopes are used to grant an application different levels of access to data on behalf of the end user. Each API may declare one or more scopes.', 'Los permisos definen el nivel de acceso que una aplicación solicita en nombre del usuario. La API puede declarar uno o más permisos.'],
  ['API requires the following scopes. Select which ones you want to grant to Swagger UI.', 'La API requiere estos permisos. Selecciona cuáles quieres conceder a Swagger UI.'],
  ['declaraciones_oauth2 (OAuth2, implicit)', 'Acceso institucional (OAuth2, implícito)'],
  ['Scopes:', 'Permisos:'],
  ['select all', 'seleccionar todos'],
  ['select none', 'quitar selección'],
  ['client_id:', 'Identificador del cliente:'],
  ['implicit', 'implícito'],
  ['Authorize', 'Autorizar'],
  ['Close', 'Cerrar'],
  ['Cancel', 'Cancelar'],
  ['Try it out', 'Probar'],
  ['Execute', 'Ejecutar'],
  ['Parameters', 'Parámetros'],
  ['No parameters', 'Sin parámetros'],
  ['Request body', 'Cuerpo de la solicitud'],
  ['Edit Value', 'Editar valor'],
  ['Schema', 'Esquema'],
  ['Responses', 'Respuestas'],
  ['Code', 'Código'],
  ['Description', 'Descripción'],
  ['Links', 'Enlaces'],
  ['Example Value', 'Ejemplo'],
  ['Media type', 'Tipo de contenido'],
  ['Controls Accept header.', 'Controla el encabezado Accept.'],
  ['No links', 'Sin enlaces'],
]);

function localizeSwagger(root = document.body) {
  if (!root) return;
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) {
    const node = walker.currentNode;
    const original = node.nodeValue || '';
    const text = original.trim();
    let translated = swaggerTranslations.get(text);
    if (!translated && text.includes('declaraciones_oauth2')) {
      translated = text.replace('declaraciones_oauth2', 'Acceso institucional');
    }
    if (!translated && text.startsWith('Application:')) translated = text.replace(/^Application:/, 'Aplicación:');
    if (!translated && text.startsWith('Authorization URL:')) translated = text.replace(/^Authorization URL:/, 'URL de autorización:');
    if (!translated && text.startsWith('Flow:')) translated = text.replace(/^Flow:/, 'Flujo:');
    if (translated) node.nodeValue = original.replace(text, translated);
  }

  root.querySelectorAll('.swagger-ui input[placeholder="Filter by tag or path"]').forEach((input) => {
    input.setAttribute('placeholder', 'Filtrar por etiqueta o ruta');
  });
  root.querySelectorAll('.swagger-ui .close-modal').forEach((button) => {
    button.setAttribute('aria-label', 'Cerrar diálogo');
    button.setAttribute('title', 'Cerrar diálogo');
  });
}

function getAuthorizedAccessToken() {
  const authorizationState = window.ui?.authSelectors?.authorized?.();
  const authorizations = authorizationState?.toJS?.() || authorizationState || {};

  for (const authorization of Object.values(authorizations)) {
    const value = authorization?.value;
    const token = value?.token || value;
    const accessToken = token?.access_token || token?.accessToken;
    if (typeof accessToken === 'string' && accessToken) return accessToken;
  }

  return null;
}

function updateAuthorizationPreview() {
  const schemeContainer = document.querySelector('#swagger-ui .scheme-container');
  if (!schemeContainer) return;

  let preview = schemeContainer.querySelector('.auth-token-preview');
  if (!preview) {
    preview = document.createElement('div');
    preview.className = 'auth-token-preview';
    preview.setAttribute('aria-live', 'polite');
    schemeContainer.prepend(preview);
  }

  const token = getAuthorizedAccessToken();
  if (!token) {
    const message = 'Inicia sesión para autorizar consultas.';
    if (preview.dataset.authorized !== 'false') preview.dataset.authorized = 'false';
    if (preview.textContent !== message) preview.textContent = message;
    return;
  }

  const maskedToken = token.length > 10
    ? `${token.slice(0, 4)}••••••${token.slice(-4)}`
    : '••••••••';
  const message = `Token recibido: ${maskedToken}`;
  if (preview.dataset.authorized !== 'true') preview.dataset.authorized = 'true';
  if (preview.textContent !== message) {
    preview.replaceChildren('Token recibido: ', Object.assign(document.createElement('code'), { textContent: maskedToken }));
  }
}

window.addEventListener('DOMContentLoaded', () => {
  localizeSwagger();
  const localizationObserver = new MutationObserver(() => localizeSwagger());
  localizationObserver.observe(document.body, { childList: true, subtree: true, characterData: true });

  const state = document.getElementById('api-state');
  const label = document.getElementById('api-state-label');
  fetch('/health', { cache: 'no-store' })
    .then((response) => {
      if (!response.ok) throw new Error('health check failed');
      return response.json();
    })
    .then(() => {
      state.dataset.state = 'ok';
      label.textContent = 'API y Mongo conectados';
    })
    .catch(() => {
      state.dataset.state = 'error';
      label.textContent = 'No se pudo verificar Mongo';
    });

  fetch('/swagger/ui-config.json', { cache: 'no-store' })
    .then((response) => {
      if (!response.ok) throw new Error('OAuth config unavailable');
      return response.json();
    })
    .then((oauth) => {
      window.ui = SwaggerUIBundle({
        url: '/swagger/v1/swagger.json',
        dom_id: '#swagger-ui',
        deepLinking: true,
        docExpansion: 'full',
        displayRequestDuration: true,
        filter: true,
        oauth2RedirectUrl: `${window.location.origin}/oauth2-redirect.html`,
        persistAuthorization: false,
        supportedSubmitMethods: ['post'],
        tryItOutEnabled: true,
        onComplete: () => {
          localizeSwagger();
          updateAuthorizationPreview();
        },
        presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
        layout: 'StandaloneLayout',
      });
      window.ui.initOAuth({
        clientId: oauth.clientId,
        appName: oauth.appName,
        scopeSeparator: ' ',
        scopes: [oauth.readScope],
        usePkceWithAuthorizationCodeGrant: false,
      });
      const authorizationObserver = new MutationObserver((mutations) => {
        const changedOutsidePreview = mutations.some((mutation) => {
          const target = mutation.target.nodeType === Node.ELEMENT_NODE
            ? mutation.target
            : mutation.target.parentElement;
          return !target?.closest('.auth-token-preview');
        });
        if (changedOutsidePreview) updateAuthorizationPreview();
      });
      authorizationObserver.observe(document.getElementById('swagger-ui'), { childList: true, subtree: true });
      updateAuthorizationPreview();
    })
    .catch(() => {
      document.getElementById('swagger-ui').textContent = 'No se pudo cargar la configuración de autorización. Recarga la página o contacta al administrador.';
    });
});
