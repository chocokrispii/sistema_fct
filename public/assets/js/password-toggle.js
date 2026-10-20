(() => {
    const svgNamespace = 'http://www.w3.org/2000/svg';
    const path = (d) => {
        const element = document.createElementNS(svgNamespace, 'path');
        element.setAttribute('d', d);
        return element;
    };

    document.querySelectorAll('input[type="password"]').forEach((input, index) => {
        const wrapper = document.createElement('span');
        wrapper.className = 'password-field';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.append(input);

        if (!input.id) {
            input.id = `password-field-${index + 1}`;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'password-field__toggle';
        button.setAttribute('aria-controls', input.id);
        button.setAttribute('aria-label', 'Mostrar contraseña');
        button.title = 'Mostrar contraseña';

        const eye = document.createElementNS(svgNamespace, 'svg');
        eye.setAttribute('viewBox', '0 0 24 24');
        eye.setAttribute('fill', 'none');
        eye.setAttribute('stroke', 'currentColor');
        eye.setAttribute('stroke-width', '1.8');
        eye.setAttribute('aria-hidden', 'true');
        const visibleIconPaths = [
            'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z',
            'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        ];
        const hiddenIconPaths = [
            'M3 3 21 21',
            'M10.6 10.6a2 2 0 0 0 2.8 2.8',
            'M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a14.7 14.7 0 0 1-3.1 4.2M6.2 6.2A15.5 15.5 0 0 0 2 12s3.6 7 10 7a10.8 10.8 0 0 0 3.3-.5',
        ];
        const renderIcon = (visible) => {
            eye.replaceChildren(...(visible ? visibleIconPaths : hiddenIconPaths).map(path));
        };
        renderIcon(false);
        button.append(eye);
        button.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-label', visible ? 'Ocultar contraseña' : 'Mostrar contraseña');
            button.setAttribute('aria-pressed', String(visible));
            button.title = visible ? 'Ocultar contraseña' : 'Mostrar contraseña';
            renderIcon(visible);
        });
        wrapper.append(button);
    });
})();
