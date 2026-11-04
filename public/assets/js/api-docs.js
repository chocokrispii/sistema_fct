window.addEventListener('load', () => {
    window.SwaggerUIBundle({
        url: 'openapi.json',
        dom_id: '#swagger-ui',
        deepLinking: true,
        displayRequestDuration: true,
        filter: true,
        persistAuthorization: false,
        supportedSubmitMethods: ['get', 'post', 'patch'],
        validatorUrl: null,
        presets: [window.SwaggerUIBundle.presets.apis],
        layout: 'BaseLayout',
    });
});
