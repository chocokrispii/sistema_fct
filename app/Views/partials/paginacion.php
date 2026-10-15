<?php
$paginaActual = (int)$paginacion['pagina'];
$hayAnterior = $paginaActual > 1;
$haySiguiente = isset($paginacion['totalPaginas'])
    ? $paginaActual < (int)$paginacion['totalPaginas']
    : !empty($paginacion['haySiguiente']);
$parametroPagina = $paginacion['parametro'] ?? 'pagina';
$crearEnlacePagina = static function (int $paginaDestino) use ($paginacion, $parametroPagina): string {
    $consulta = $_GET;
    if ($parametroPagina === 'candidatos_pagina') {
        $paginas = is_array($consulta[$parametroPagina] ?? null) ? $consulta[$parametroPagina] : [];
        $paginas[(string)$paginacion['clave']] = $paginaDestino;
        $consulta[$parametroPagina] = $paginas;
    } else {
        $consulta[$parametroPagina] = $paginaDestino;
    }

    return 'index.php?' . http_build_query($consulta, '', '&', PHP_QUERY_RFC3986);
};
?>
<?php if ($hayAnterior || $haySiguiente): ?>
    <nav class="pagination" aria-label="Paginación">
        <?php if ($hayAnterior): ?>
            <a href="<?= htmlspecialchars($crearEnlacePagina($paginaActual - 1), ENT_QUOTES, 'UTF-8') ?>">Anterior</a>
        <?php endif; ?>
        <span>
            Página <?= $paginaActual ?>
            <?php if (isset($paginacion['totalPaginas'])): ?> de <?= (int)$paginacion['totalPaginas'] ?><?php endif; ?>
        </span>
        <?php if ($haySiguiente): ?>
            <a href="<?= htmlspecialchars($crearEnlacePagina($paginaActual + 1), ENT_QUOTES, 'UTF-8') ?>">Siguiente</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
