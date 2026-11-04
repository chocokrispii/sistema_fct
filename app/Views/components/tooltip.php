<?php
$tooltipId = (string)($tooltipId ?? '');
$tooltipText = (string)($tooltipText ?? '');
?>
<span class="tooltip">
    <button class="tooltip__trigger" type="button" aria-label="Ayuda sobre este campo" aria-describedby="<?= htmlspecialchars($tooltipId, ENT_QUOTES, 'UTF-8') ?>">
        <span aria-hidden="true">i</span>
        <span class="tooltip__bubble" role="tooltip"><?= htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8') ?></span>
    </button>
    <span class="visually-hidden" id="<?= htmlspecialchars($tooltipId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8') ?></span>
</span>
