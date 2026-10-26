(() => {
    const svgNamespace = 'http://www.w3.org/2000/svg';
    const visibleIconPaths = [
        'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z',
        'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    ];
    const hiddenIconPaths = [
        'M3 3 21 21',
        'M10.6 10.6a2 2 0 0 0 2.8 2.8',
        'M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a14.7 14.7 0 0 1-3.1 4.2M6.2 6.2A15.5 15.5 0 0 0 2 12s3.6 7 10 7a10.8 10.8 0 0 0 3.3-.5',
    ];
    const makePath = (d) => {
        const element = document.createElementNS(svgNamespace, 'path');
        element.setAttribute('d', d);
        return element;
    };
    const renderIcon = (button, visible) => {
        const eye = button.querySelector('svg');
        eye.replaceChildren(...(visible ? visibleIconPaths : hiddenIconPaths).map(makePath));
    };
    const setVisibility = (input, visible) => {
        input.type = visible ? 'text' : 'password';

        const button = input.parentElement.querySelector('[data-password-field-toggle]');
        if (button) {
            const label = visible ? 'Ocultar contraseña' : 'Mostrar contraseña';
            button.setAttribute('aria-label', label);
            button.setAttribute('aria-pressed', String(visible));
            button.title = label;
            renderIcon(button, visible);
        }

        if (input.id) {
            document.querySelectorAll('[data-password-toggle]').forEach((toggle) => {
                if (toggle instanceof HTMLInputElement
                    && toggle.type === 'checkbox'
                    && toggle.dataset.passwordToggle === input.id) {
                    toggle.checked = visible;
                }
            });
        }
    };

    document.querySelectorAll('input[type="password"]').forEach((input, index) => {
        if (input.dataset.passwordFieldToggleInitialized === 'true') {
            return;
        }

        let id = input.id;
        if (!id) {
            let suffix = index + 1;
            id = `password-field-${suffix}`;
            while (document.getElementById(id)) {
                suffix += 1;
                id = `password-field-${suffix}`;
            }
            input.id = id;
        }

        const wrapper = document.createElement('span');
        wrapper.className = 'password-field';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.append(input);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'password-field__toggle';
        button.dataset.passwordFieldToggle = 'true';
        button.setAttribute('aria-controls', input.id);
        button.setAttribute('aria-label', 'Mostrar contraseña');
        button.setAttribute('aria-pressed', 'false');
        button.title = 'Mostrar contraseña';

        const eye = document.createElementNS(svgNamespace, 'svg');
        eye.setAttribute('viewBox', '0 0 24 24');
        eye.setAttribute('fill', 'none');
        eye.setAttribute('stroke', 'currentColor');
        eye.setAttribute('stroke-width', '1.8');
        eye.setAttribute('aria-hidden', 'true');
        button.append(eye);
        renderIcon(button, false);
        wrapper.append(button);
        input.dataset.passwordFieldToggleInitialized = 'true';
    });

    if (document.documentElement.dataset.passwordToggleHandlersInitialized === 'true') {
        return;
    }
    document.documentElement.dataset.passwordToggleHandlersInitialized = 'true';

    document.addEventListener('change', (event) => {
        const toggle = event.target;
        if (!(toggle instanceof HTMLInputElement) || toggle.type !== 'checkbox'
            || !toggle.matches('[data-password-toggle]')) {
            return;
        }

        const password = document.getElementById(toggle.dataset.passwordToggle || '');
        if (password instanceof HTMLInputElement
            && (password.type === 'password' || password.type === 'text')) {
            setVisibility(password, toggle.checked);
        }
    });

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }
        const button = event.target.closest('[data-password-field-toggle]');
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }

        const input = document.getElementById(button.getAttribute('aria-controls') || '');
        if (input instanceof HTMLInputElement
            && (input.type === 'password' || input.type === 'text')) {
            setVisibility(input, input.type === 'password');
        }
    });
})();
