<h2>Error</h2>
<p><?php
if (!empty($dbUnavailable)) {
    echo 'La base de datos no está disponible en este momento. Tus datos no se han guardado; inténtalo de nuevo en unos minutos.';
} elseif ($status === 403) {
    echo 'No tienes permiso para realizar esta operación.';
} else {
    echo 'Se ha producido un error interno. Inténtalo más tarde.';
}
?></p>
