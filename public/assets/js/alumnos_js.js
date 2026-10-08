/**
 * Muestra u oculta el campo del tutor legal si el check de menor está activo
 */
function toggleTutor() {
    const chk = document.getElementById('chk-menor');
    const box = document.getElementById('campo-tutor');
    const input = document.getElementById('tutor');
    
    if (chk && chk.checked) {
        if (box) box.style.display = 'block';
        if (input) input.setAttribute('required', 'required');
    } else {
        if (box) box.style.display = 'none';
        if (input) {
            input.removeAttribute('required');
            input.value = '';
        }
    }
}

document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll('.persist').forEach(el => {
        try {
            localStorage.removeItem(el.id);
        } catch (error) {
            console.warn('No se pudo eliminar un dato heredado del formulario.', error);
        }
    });

    toggleTutor();

    const formGeneral = document.getElementById('form-general-alumno');
    if (formGeneral) {
        formGeneral.addEventListener('submit', (e) => {
            e.preventDefault();
            alert('Este formulario heredado es solo una maqueta: no envía ni guarda datos. Usa el registro oficial del sistema.');
        });
    }
});
