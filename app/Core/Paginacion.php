<?php

final class Paginacion
{
    public static function desdeEntrada(mixed $valor): int
    {
        $pagina = filter_var($valor, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);

        return $pagina === false ? 1 : $pagina;
    }

    public static function calcular(int $total, int $pagina, int $porPagina): array
    {
        if ($total < 0 || $porPagina < 1) {
            throw new InvalidArgumentException('Los valores de paginación no son válidos.');
        }

        $totalPaginas = max(1, (int)ceil($total / $porPagina));
        $pagina = min(max(1, $pagina), $totalPaginas);

        return [
            'pagina' => $pagina,
            'porPagina' => $porPagina,
            'total' => $total,
            'totalPaginas' => $totalPaginas,
            'offset' => ($pagina - 1) * $porPagina,
        ];
    }
}
