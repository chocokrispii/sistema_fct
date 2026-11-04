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
    const logoFrame = form.querySelector('[data-brand-logo-frame]');
    const logoShape = form.querySelector('[data-brand-logo-shape]');
    const sampleButton = form.querySelector('[data-brand-logo-sample]');
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

    function showLogoFile(file) {
        if (!(logoImage instanceof HTMLImageElement)) {
            return;
        }
        if (previewUrl !== null) {
            URL.revokeObjectURL(previewUrl);
        }
        previewUrl = URL.createObjectURL(file);
        logoImage.src = previewUrl;
        logoImage.hidden = false;
        if (logoEmpty instanceof HTMLElement) {
            logoEmpty.hidden = true;
        }
        if (removeLogo instanceof HTMLInputElement) {
            removeLogo.checked = false;
        }
        if (logoStatus instanceof HTMLElement) {
            logoStatus.textContent = `Vista previa: ${file.name}. El logo conserva su proporción dentro de la forma seleccionada.`;
        }
    }

    function updateLogoShape() {
        if (logoFrame instanceof HTMLElement && logoShape instanceof HTMLSelectElement) {
            logoFrame.dataset.shape = logoShape.value;
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
            showLogoFile(file);
        });
    }

    if (logoShape instanceof HTMLSelectElement) {
        logoShape.addEventListener('change', updateLogoShape);
        updateLogoShape();
    }

    if (sampleButton instanceof HTMLButtonElement && logoInput instanceof HTMLInputElement) {
        sampleButton.addEventListener('click', async () => {
            sampleButton.disabled = true;
            if (logoStatus instanceof HTMLElement) {
                logoStatus.textContent = 'Cargando el logo de ejemplo…';
            }
            try {
                const response = await fetch('assets/images/logo-sistema-fct-ejemplo.svg', { credentials: 'same-origin' });
                if (!response.ok) {
                    throw new Error('No se pudo descargar el logo de ejemplo.');
                }
                const blob = await response.blob();
                const file = new File([blob], 'logo-sistema-fct-ejemplo.svg', { type: 'image/svg+xml' });
                const transfer = new DataTransfer();
                transfer.items.add(file);
                logoInput.files = transfer.files;
                showLogoFile(file);
            } catch (error) {
                if (logoStatus instanceof HTMLElement) {
                    logoStatus.textContent = error instanceof Error
                        ? `${error.message} Puedes descargar el SVG y seleccionarlo manualmente.`
                        : 'No se pudo cargar el logo de ejemplo. Descarga el SVG y selecciónalo manualmente.';
                }
            } finally {
                sampleButton.disabled = false;
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
