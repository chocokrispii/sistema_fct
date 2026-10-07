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

/**
 * Persistencia automática en LocalStorage al navegar
 */
document.addEventListener("DOMContentLoaded", () => {
    // 1. Cargar los datos guardados al iniciar la página
    document.querySelectorAll('.persist').forEach(el => {
        const saved = localStorage.getItem(el.id);
        if (saved !== null) {
            if (el.type === 'checkbox') el.checked = saved === 'true';
            else el.value = saved;
        }
    });
    
    // Chequeo inicial del tutor legal
    toggleTutor();

    // 2. Guardar cambios en el almacenamiento sobre la marcha al escribir
    document.querySelectorAll('.persist').forEach(el => {
        el.addEventListener('input', () => {
            localStorage.setItem(el.id, el.type === 'checkbox' ? el.checked : el.value);
        });
    });

    // 3. Captura del envío del formulario completo
    const formGeneral = document.getElementById('form-general-alumno');
    if (formGeneral) {
        formGeneral.addEventListener('submit', (e) => {
            e.preventDefault();
            alert('¡Portal actualizado y guardado correctamente en tu navegador de forma segura!');
        });
    }
});
