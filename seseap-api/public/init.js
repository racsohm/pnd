window.addEventListener('DOMContentLoaded', () => {
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
        displayRequestDuration: true,
        filter: true,
        oauth2RedirectUrl: `${window.location.origin}/swagger/oauth2-redirect.html`,
        persistAuthorization: false,
        supportedSubmitMethods: ['post'],
        tryItOutEnabled: true,
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
    })
    .catch(() => {
      document.getElementById('swagger-ui').textContent = 'No se pudo cargar la configuración de autorización. Recarga la página o contacta al administrador.';
    });
});
