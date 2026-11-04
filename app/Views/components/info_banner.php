<?php
$infoBannerTitle = (string)($infoBannerTitle ?? '');
$infoBannerText = (string)($infoBannerText ?? '');
$infoBannerLinks = $infoBannerLinks ?? [];
?>
<aside class="info-banner" role="note">
    <h3><?= htmlspecialchars($infoBannerTitle, ENT_QUOTES, 'UTF-8') ?></h3>
    <p><?= htmlspecialchars($infoBannerText, ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($infoBannerLinks !== []): ?>
        <div class="info-banner__links">
            <?php foreach ($infoBannerLinks as $link): ?>
                <a href="<?= htmlspecialchars((string)$link['href'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$link['label'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</aside>
