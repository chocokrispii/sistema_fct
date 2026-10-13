(() => {
    const form = document.querySelector('[data-student-profile]');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
    const normalizeDocument = (value) => value.toUpperCase().replace(/[\s-]/g, '');
    const validDocument = (value) => {
        const document = normalizeDocument(value);
        let digits;
        let control;
        const dni = /^(\d{8})([A-Z])$/.exec(document);
        const nie = /^([XYZ])(\d{7})([A-Z])$/.exec(document);
        if (dni) {
            digits = Number(dni[1]);
            control = dni[2];
        } else if (nie) {
            digits = Number(({ X: '0', Y: '1', Z: '2' })[nie[1]] + nie[2]);
            control = nie[3];
        } else {
            return false;
        }
        return letters[digits % 23] === control;
    };
    const phoneDigits = (value) => value.trim()
        .replace(/^(?:\+34|0034)\s*/i, '')
        .replace(/[\s().-]/g, '');
    const namePattern = /^[\p{L}\p{M}][\p{L}\p{M} \t.'’\-]*$/u;
    const localToday = () => {
        const now = new Date();
        return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    };

    const validate = (input) => {
        let message = '';
        if (input.matches('[data-spanish-id]') && !validDocument(input.value)) {
            message = 'Introduce un DNI/NIE con una letra de control válida.';
        } else if (input.matches('[data-spanish-phone]') && !/^[6789]\d{8}$/.test(phoneDigits(input.value))) {
            message = 'Introduce un teléfono español válido de nueve cifras.';
        } else if (input.matches('[data-person-name]') && !namePattern.test(input.value.trim())) {
            message = 'Usa un nombre con letras, espacios, apóstrofos, puntos o guiones.';
        } else if (input.type === 'date' && input.value && input.value > localToday()) {
            message = 'La fecha no puede ser futura.';
        }
        input.setCustomValidity(message);
        input.setAttribute('aria-invalid', message ? 'true' : 'false');
        const feedback = input.parentElement?.querySelector('[data-field-error]');
        if (feedback && message) {
            feedback.textContent = message;
        } else if (feedback && !message) {
            feedback.textContent = '';
        }
        return message === '';
    };

    form.querySelectorAll('input').forEach((input) => {
        input.addEventListener('input', () => validate(input));
        input.addEventListener('blur', () => validate(input));
    });
    form.addEventListener('submit', (event) => {
        const invalid = [...form.querySelectorAll('input')].filter((input) => !validate(input));
        if (invalid.length > 0) {
            event.preventDefault();
            invalid[0].focus();
            invalid[0].reportValidity();
        }
    });
})();
