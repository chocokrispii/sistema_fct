<section class="branding-page" aria-labelledby="branding-title">
    <header class="branding-page__intro">
        <span class="student-registration__eyebrow">Configuración del portal</span>
        <h2 id="branding-title">Diseño y marca</h2>
        <p>Personaliza los colores y el logo del portal. La previsualización refleja los cambios al instante; al guardar, estarán disponibles para todas las personas usuarias.</p>
    </header>

    <?php if ($error !== ''): ?>
        <p class="message message-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
    <?php if ($saved): ?>
        <p class="message message-success" role="status">La identidad visual se ha actualizado correctamente.</p>
    <?php endif; ?>

    <form class="branding-form" method="POST" action="index.php?action=diseno" enctype="multipart/form-data" data-brand-design>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars(CsrfMiddleware::token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="2097152">

        <fieldset class="branding-form__palette">
            <legend>Paleta de colores</legend>
            <p>Los tonos se adaptan automáticamente para conservar el contraste. El acento resalta la navegación activa; en modo oscuro, los elementos principales usan el color secundario.</p>
            <?php foreach (['primary' => 'Primario', 'secondary' => 'Secundario', 'accent' => 'Acento'] as $name => $label): ?>
                <label class="branding-color" for="branding-<?= $name ?>">
                    <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                    <input
                        id="branding-<?= $name ?>"
                        type="color"
                        name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                        value="<?= htmlspecialchars($palette[$name], ENT_QUOTES, 'UTF-8') ?>"
                        data-brand-color
                    >
                    <output for="branding-<?= $name ?>" data-color-value><?= htmlspecialchars($palette[$name], ENT_QUOTES, 'UTF-8') ?></output>
                </label>
            <?php endforeach; ?>
            <p class="branding-form__preview-status" aria-live="polite" data-brand-status>Vista previa de la paleta actual.</p>
        </fieldset>

        <fieldset class="branding-form__logo">
            <legend>Logo del portal</legend>
            <p class="branding-form__instructions">Añade un logo en SVG, PNG o JPG (máximo 2 MB). Para un resultado nítido, recomendamos SVG o PNG transparente de 512 × 128 px.</p>
            <div class="branding-logo__settings">
                <label class="branding-logo__field" for="branding-logo">
                    <span>Archivo del logo</span>
                    <input id="branding-logo" type="file" name="logo" accept=".svg,.png,.jpg,.jpeg,image/svg+xml,image/png,image/jpeg" data-brand-logo>
                    <span class="branding-logo__hint">Al seleccionar un archivo, verás el resultado antes de guardar.</span>
                </label>
                <label class="branding-logo__field" for="branding-logo-shape">
                    <span>Forma del marco</span>
                    <select id="branding-logo-shape" name="logo_shape" data-brand-logo-shape>
                        <option value="original" <?= $logoShape === 'original' ? 'selected' : '' ?>>Original (sin recorte)</option>
                        <option value="rounded" <?= $logoShape === 'rounded' ? 'selected' : '' ?>>Esquinas redondeadas</option>
                        <option value="square" <?= $logoShape === 'square' ? 'selected' : '' ?>>Cuadrada</option>
                        <option value="circle" <?= $logoShape === 'circle' ? 'selected' : '' ?>>Circular</option>
                    </select>
                    <span class="branding-logo__hint">La forma solo cambia el marco; el logo mantiene su proporción.</span>
                </label>
            </div>
            <section class="branding-logo-preview__mock" aria-label="Vista previa del logo en la cabecera">
                <div class="branding-logo-preview__caption">
                    <strong>Vista previa</strong>
                    <span>Así aparecerá en la cabecera del portal</span>
                </div>
                <div class="branding-logo-preview__header">
                    <div class="branding-logo-preview__frame" data-brand-logo-frame data-shape="<?= htmlspecialchars($logoShape, ENT_QUOTES, 'UTF-8') ?>">
                        <?php if ($logoUrl !== null): ?>
                            <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa del logo actual" data-brand-logo-image>
                        <?php else: ?>
                            <span data-brand-logo-empty>No hay un logo personalizado</span>
                            <img alt="Vista previa del logo" data-brand-logo-image hidden>
                        <?php endif; ?>
                    </div>
                    <span class="branding-logo-preview__nav" aria-hidden="true">Inicio　 Ofertas　 Empresas</span>
                </div>
            </section>
            <div class="branding-logo-example">
                <div class="branding-logo-example__art">
                    <img src="assets/images/logo-sistema-fct-ejemplo.svg" alt="Logo de ejemplo de Sistema FCT">
                </div>
                <div class="branding-logo-example__content">
                    <strong>Logo de ejemplo Sistema FCT</strong>
                    <p>Prueba la vista previa con una propuesta visual de Sistema FCT.</p>
                    <div class="branding-logo-example__actions">
                        <button type="button" class="branding-logo-example__use" data-brand-logo-sample>Probar este logo</button>
                        <a href="assets/images/logo-sistema-fct-ejemplo.svg" download="logo-sistema-fct-ejemplo.svg">Descargar ejemplo</a>
                    </div>
                </div>
            </div>
            <?php if ($logoUrl !== null): ?>
                <label class="branding-form__remove">
                    <input type="checkbox" name="remove_logo" value="1" data-brand-remove>
                    Retirar el logo personalizado
                </label>
            <?php endif; ?>
            <p class="branding-form__preview-status" aria-live="polite" data-brand-logo-status></p>
        </fieldset>

        <div class="branding-form__actions">
            <button type="submit">Guardar diseño</button>
            <a class="student-portal__text-action" href="index.php">Ver portal</a>
        </div>
    </form>
</section>
