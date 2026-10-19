(() => {
    const panel = document.querySelector('[data-api-test-form]');
    if (!(panel instanceof HTMLElement)) {
        return;
    }

    const tokenInput = panel.querySelector('[name="token"]');
    const methodInput = panel.querySelector('[name="method"]');
    const resourceInput = panel.querySelector('[name="resource"]');
    const idInput = panel.querySelector('[name="resource_id"]');
    const pageInput = panel.querySelector('[name="page"]');
    const perPageInput = panel.querySelector('[name="per_page"]');
    const bodyInput = panel.querySelector('[name="body"]');
    const confirmWrite = panel.querySelector('[name="confirm_write"]');
    const sendButton = panel.querySelector('[data-api-send]');
    const queryFields = panel.querySelector('[data-api-query-fields]');
    const status = document.querySelector('[data-api-status]');
    const response = document.querySelector('[data-api-response]');

    const updateForm = () => {
        const method = methodInput.value;
        const isRead = method === 'GET';
        queryFields.hidden = !isRead || idInput.value !== '';
        confirmWrite.closest('label').hidden = isRead;
        bodyInput.required = !isRead;
    };

    methodInput.addEventListener('change', updateForm);
    idInput.addEventListener('input', updateForm);
    updateForm();

    sendButton.addEventListener('click', async () => {
        status.textContent = '';
        response.textContent = '';

        const method = methodInput.value;
        const resource = resourceInput.value;
        const resourceId = idInput.value.trim();
        if (!['GET', 'POST', 'PATCH'].includes(method)
            || !['alumnos', 'empresas', 'ofertas', 'convenios', 'asignaciones'].includes(resource)
            || (resourceId !== '' && !/^[1-9]\d*$/.test(resourceId))) {
            status.textContent = 'Selecciona un método, recurso e ID válidos.';
            return;
        }
        if ((method === 'POST' && resourceId !== '')
            || (method === 'PATCH' && resourceId === '')) {
            status.textContent = method === 'POST'
                ? 'Para crear un registro, deja el campo ID vacío.'
                : 'Para actualizar un registro, indica su ID.';
            return;
        }
        if (method !== 'GET' && !confirmWrite.checked) {
            status.textContent = 'Confirma que entiendes que la escritura se guardará en la base actual.';
            return;
        }

        const token = tokenInput.value.trim();
        if (token === '') {
            status.textContent = 'Introduce un token API activo.';
            tokenInput.focus();
            tokenInput.reportValidity();
            return;
        }

        let requestBody;
        if (method !== 'GET') {
            try {
                requestBody = JSON.parse(bodyInput.value);
                if (!requestBody || Array.isArray(requestBody) || typeof requestBody !== 'object') {
                    throw new Error('El cuerpo debe ser un objeto JSON.');
                }
            } catch (error) {
                status.textContent = error instanceof Error ? error.message : 'El JSON no es válido.';
                return;
            }
        }

        tokenInput.value = '';
        const path = resource + (resourceId === '' ? '' : '/' + resourceId);
        const url = new URL('api/v1/' + path, window.location.href);
        if (method === 'GET' && resourceId === '') {
            url.searchParams.set('page', pageInput.value);
            url.searchParams.set('per_page', perPageInput.value);
        }

        const options = {
            method,
            headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
            cache: 'no-store',
        };
        if (requestBody !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(requestBody);
        }

        status.textContent = 'Enviando solicitud…';
        sendButton.disabled = true;
        try {
            const result = await fetch(url, options);
            const responseText = await result.text();
            let formatted;
            try {
                formatted = JSON.stringify(JSON.parse(responseText), null, 2);
            } catch {
                formatted = responseText || '(respuesta vacía)';
            }
            status.textContent = 'HTTP ' + result.status + (result.ok ? ' · solicitud completada' : ' · revisa el error devuelto');
            response.textContent = formatted;
        } catch (error) {
            status.textContent = 'No se pudo conectar con la API: ' + (error instanceof Error ? error.message : 'error de red');
        } finally {
            tokenInput.value = '';
            confirmWrite.checked = false;
            bodyInput.value = '';
            options.headers.Authorization = '';
            sendButton.disabled = false;
        }
    });
})();