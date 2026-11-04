(() => {
    const form = document.querySelector('[data-brand-design]');
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    const colorInputs = [...form.querySelectorAll('[data-brand-color]')];
    const status = form.querySelector('[data-brand-status]');
    const logoInput = form.querySelector('[data-brand-logo]');
    const logoImage = form.querySelector('[data-brand-logo-image]');
    const logoEmpty = form.querySelector('[data-brand-logo-empty]');
    const logoStatus = form.querySelector('[data-brand-logo-status]');
    const removeLogo = form.querySelector('[data-brand-remove]');
    let previewUrl = null;
    let currentLogoUrl = logoImage instanceof HTMLImageElement ? logoImage.getAttribute('src') : null;

    function updatePalette() {
        const stylesheet = document.getElementById('brand-theme-styles');
        if (!(stylesheet instanceof HTMLLinkElement)) {
            return;
        }
        const url = new URL('index.php?action=branding-styles', document.baseURI);
        for (const input of colorInputs) {
            if (!(input instanceof HTMLInputElement) || !/#[0-9a-f]{6}/i.test(input.value)) {
                continue;
            }
            const name = input.name;
            url.searchParams.set(name, input.value);
            const value = input.parentElement?.querySelector('[data-color-value]');
            if (value instanceof HTMLOutputElement) {
                value.value = input.value.toUpperCase();
            }
        }
        stylesheet.href = url.toString();
        if (status instanceof HTMLElement) {
            status.textContent = 'Vista previa actualizada. Los colores de texto se ajustan para mantener contraste accesible.';
        }
    }

    function restoreLogo() {
        if (!(logoImage instanceof HTMLImageElement)) {
            return;
        }
        if (previewUrl !== null) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = null;
        }
        if (currentLogoUrl !== null) {
            logoImage.src = currentLogoUrl;
            logoImage.hidden = false;
            if (logoEmpty instanceof HTMLElement) {
                logoEmpty.hidden = true;
            }
        } else {
            logoImage.removeAttribute('src');
            logoImage.hidden = true;
            if (logoEmpty instanceof HTMLElement) {
                logoEmpty.hidden = false;
            }
        }
    }

    function hideLogoPreview() {
        if (previewUrl !== null) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = null;
        }
        if (logoImage instanceof HTMLImageElement) {
            logoImage.hidden = true;
        }
        if (logoEmpty instanceof HTMLElement) {
            logoEmpty.hidden = false;
        }
    }

    for (const input of colorInputs) {
        input.addEventListener('input', updatePalette);
        input.addEventListener('change', updatePalette);
    }

    if (logoInput instanceof HTMLInputElement) {
        logoInput.addEventListener('change', () => {
            const file = logoInput.files?.[0];
            if (!(file instanceof File)) {
                restoreLogo();
                return;
            }
            if (file.size > 2 * 1024 * 1024) {
                logoInput.value = '';
                restoreLogo();
                if (logoStatus instanceof HTMLElement) {
                    logoStatus.textContent = 'El logo supera el peso máximo de 2 MB.';
                }
                return;
            }
            if (!['image/svg+xml', 'image/png', 'image/jpeg'].includes(file.type)) {
                logoInput.value = '';
                restoreLogo();
                if (logoStatus instanceof HTMLElement) {
                    logoStatus.textContent = 'Selecciona un archivo SVG, PNG o JPG.';
                }
                return;
            }
            if (previewUrl !== null) {
                URL.revokeObjectURL(previewUrl);
            }
            previewUrl = URL.createObjectURL(file);
            if (logoImage instanceof HTMLImageElement) {
                logoImage.src = previewUrl;
                logoImage.hidden = false;
            }
            if (logoEmpty instanceof HTMLElement) {
                logoEmpty.hidden = true;
            }
            if (removeLogo instanceof HTMLInputElement) {
                removeLogo.checked = false;
            }
            if (logoStatus instanceof HTMLElement) {
                logoStatus.textContent = `Vista previa: ${file.name}. La imagen se ajustará sin deformarse ni desbordar la cabecera.`;
            }
        });
    }

    if (removeLogo instanceof HTMLInputElement) {
        removeLogo.addEventListener('change', () => {
            if (removeLogo.checked) {
                hideLogoPreview();
                if (logoStatus instanceof HTMLElement) {
                    logoStatus.textContent = 'El logo personalizado se retirará al guardar.';
                }
            } else {
                restoreLogo();
                if (logoStatus instanceof HTMLElement) {
                    logoStatus.textContent = '';
                }
            }
        });
    }

    form.addEventListener('submit', (event) => {
        if (removeLogo instanceof HTMLInputElement && removeLogo.checked && logoInput instanceof HTMLInputElement && logoInput.files?.length) {
            event.preventDefault();
            if (logoStatus instanceof HTMLElement) {
                logoStatus.textContent = 'Elige subir un logo nuevo o retirar el actual, no ambas opciones.';
            }
        }
    });
})();
