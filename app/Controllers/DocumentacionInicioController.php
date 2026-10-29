<?php

declare(strict_types=1);

require_once __DIR__ . '/../Models/DocumentacionInicio.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../../config/storage.php';

final class DocumentacionInicioController
{
    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= getPDOConnection();
    }

    public function index(): void
    {
        View::render('alumnos/documentacion-inicio', [
            'documentos' => (new DocumentacionInicio($this->db))->listarPorUsuario((int)$_SESSION['usuario_id']),
        ]);
    }

    public function descargar(): void
    {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $documento = $id === false ? null : (new DocumentacionInicio($this->db))->obtener((int)$_SESSION['usuario_id'], (int)$id);
        if ($documento === null) throw new RuntimeException('No se encontró el documento solicitado.');
        $root = realpath(fctStoragePath());
        $path = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $documento['archivo_path']));
        if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('El documento solicitado no está disponible.');
        }
        (new Auditoria($this->db))->registrar((int)$_SESSION['usuario_id'], 'documentacion_inicio.descargada', 'documentacion_inicio_fct', (int)$documento['id']);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string)filesize($path));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $documento['nombre_original']) . '"');
        readfile($path);
        exit;
    }
}
