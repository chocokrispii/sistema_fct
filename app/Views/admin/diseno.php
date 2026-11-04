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
            <p class="branding-form__instructions">Formato recomendado: SVG o PNG con fondo transparente | Dimensiones óptimas: 512x128px | Peso máx: 2MB. También se aceptan imágenes JPG.</p>
            <label for="branding-logo">Subir o reemplazar logo</label>
            <input id="branding-logo" type="file" name="logo" accept=".svg,.png,.jpg,.jpeg,image/svg+xml,image/png,image/jpeg" data-brand-logo>
            <div class="branding-logo-preview" data-brand-logo-preview>
                <?php if ($logoUrl !== null): ?>
                    <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Vista previa del logo actual" data-brand-logo-image>
                <?php else: ?>
                    <span data-brand-logo-empty>No hay un logo personalizado. Se mostrará la marca predeterminada.</span>
                    <img alt="Vista previa del logo" data-brand-logo-image hidden>
                <?php endif; ?>
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
