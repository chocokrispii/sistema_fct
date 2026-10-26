(() => {
    const jornada = document.getElementById('offer-jornada');
    if (!(jornada instanceof HTMLSelectElement)) {
        return;
    }

    const rows = document.querySelectorAll('.offer-weekday');
    let wasPartida = jornada.value === 'partida';

    const syncScheduleFields = () => {
        const isPartida = jornada.value === 'partida';
        if (isPartida && !wasPartida) {
            rows.forEach((row) => {
                const firstRangeEnd = row.querySelector('input[name$="[hora_fin]"]');
                if (firstRangeEnd && !firstRangeEnd.dataset.userEdited && firstRangeEnd.value === '17:00') {
                    firstRangeEnd.value = '14:00';
                }

                const secondRangeStart = row.querySelector('input[name$="[hora_inicio_2]"]');
                if (secondRangeStart && !secondRangeStart.dataset.userEdited && secondRangeStart.value === '') {
                    secondRangeStart.value = '16:00';
                }

                const secondRangeEnd = row.querySelector('input[name$="[hora_fin_2]"]');
                if (secondRangeEnd && !secondRangeEnd.dataset.userEdited && secondRangeEnd.value === '') {
                    secondRangeEnd.value = '21:00';
                }
            });
        }
        wasPartida = isPartida;

        document.querySelectorAll('[data-partida-column]').forEach((column) => {
            column.hidden = !isPartida;
        });

        rows.forEach((row) => {
            const active = row.querySelector('input[type="checkbox"]')?.checked === true;
            row.querySelectorAll('input[type="time"]').forEach((field) => {
                const isSecondRange = field.hasAttribute('data-partida-field');
                field.disabled = !active || (isSecondRange && !isPartida);
                field.required = active && (!isSecondRange || isPartida);
            });

            const activeLabel = row.querySelector('[data-day-active-label]');
            if (activeLabel) {
                activeLabel.textContent = active ? 'Sí' : 'No';
            }
        });
    };

    jornada.addEventListener('change', syncScheduleFields);
    rows.forEach((row) => {
        row.querySelector('input[type="checkbox"]')?.addEventListener('change', syncScheduleFields);
        row.querySelectorAll('input[type="time"]').forEach((field) => {
            const markUserEdited = () => {
                field.dataset.userEdited = 'true';
            };
            field.addEventListener('input', markUserEdited);
            field.addEventListener('change', markUserEdited);
        });
    });
    syncScheduleFields();
})();
