const siteSelect = document.querySelector('[data-offer-site-select]');
const newSiteDetails = document.querySelector('[data-offer-new-site]');

if (siteSelect instanceof HTMLSelectElement && newSiteDetails instanceof HTMLDetailsElement) {
    const newSiteInputs = newSiteDetails.querySelectorAll('input[name^="nueva_sede_"]');

    const updateNewSiteFields = () => {
        const addingSite = siteSelect.value === 'nueva';
        newSiteDetails.open = addingSite;
        newSiteInputs.forEach((input) => {
            if (input instanceof HTMLInputElement) {
                input.required = addingSite && input.name !== 'nueva_sede_telefono';
            }
        });
    };

    siteSelect.addEventListener('change', updateNewSiteFields);
    updateNewSiteFields();
}
