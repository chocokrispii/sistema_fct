<?php

declare(strict_types=1);

require_once __DIR__ . '/../Models/EvaluacionEmpresa.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../Core/FileScanner.php';
require_once __DIR__ . '/../Core/EvaluationMailer.php';
require_once __DIR__ . '/../../config/storage.php';

final class EvaluacionEmpresaController
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const TYPES = [
        'pdf' => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= getPDOConnection();
    }

    public function index(): void
    {
        $model = new EvaluacionEmpresa($this->db);
        View::render('empresas/evaluaciones', [
            'evaluaciones' => $model->listarPorEmpresa((int)$_SESSION['usuario_id']),
            'documentosInicio' => $model->listarDocumentosInicio((int)$_SESSION['usuario_id']),
            'csrfToken' => CsrfMiddleware::token(),
            'error' => is_string($_GET['error'] ?? null) ? $_GET['error'] : '',
            'guardada' => isset($_GET['guardada']),
        ]);
    }

    public function descargarDocumentoInicio(): void
    {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $documento = $id === false ? null : (new EvaluacionEmpresa($this->db))->obtenerDocumentoInicio((int)$id, (int)$_SESSION['usuario_id']);
        if ($documento === null) { throw new RuntimeException('No se encontró el documento solicitado.'); }
        $root = realpath(fctStoragePath());
        $path = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $documento['archivo_path']));
        if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('El documento solicitado no está disponible.');
        }
        (new Auditoria($this->db))->registrar((int)$_SESSION['usuario_id'], 'documentacion_inicio.descargada_empresa', 'documentacion_inicio_fct', (int)$documento['id']);
        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string)filesize($path));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $documento['nombre_original']) . '"');
        readfile($path);
        exit;
    }

    public function guardar(): void
    {
        $asignacionId = filter_var($_POST['asignacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $dictamen = $_POST['dictamen'] ?? '';
        $archivo = $_FILES['informe_evaluacion'] ?? null;
        if ($asignacionId === false || !is_string($dictamen) || !in_array($dictamen, ['Apto', 'No Apto'], true)) {
            $this->failure('Selecciona un dictamen válido: Apto o No Apto.');
        }
        if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($archivo['name'] ?? null) || !is_string($archivo['tmp_name'] ?? null)
            || !is_uploaded_file($archivo['tmp_name']) || !is_int($archivo['size'] ?? null)
            || $archivo['size'] < 1 || $archivo['size'] > self::MAX_BYTES) {
            $this->failure('Adjunta un informe PDF, Excel o Word de hasta 10 MB.');
        }
        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        if (!isset(self::TYPES[$extension]) || $mime !== self::TYPES[$extension]) {
            $this->failure('Solo se admiten archivos PDF (.pdf), Excel (.xlsx) o Word (.docx) válidos.');
        }
        FileScanner::scan($archivo['tmp_name']);

        $model = new EvaluacionEmpresa($this->db);
        $assignment = $model->obtenerAsignacionPropia((int)$asignacionId, (int)$_SESSION['usuario_id']);
        if ($assignment === null) {
            $this->failure('La asignación no pertenece a tu empresa o ya está cancelada.');
        }
        $relativePath = 'uploads/evaluaciones/' . (int)$assignment['empresa_id'] . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
        $directory = fctStoragePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, dirname($relativePath));
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            $this->failure('No se pudo preparar el almacenamiento privado.', 500);
        }
        chmod($directory, 0700);
        $destination = fctStoragePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!move_uploaded_file($archivo['tmp_name'], $destination) || !chmod($destination, 0600)) {
            if (is_file($destination)) { unlink($destination); }
            $this->failure('No se pudo guardar el informe.', 500);
        }

        try {
            $this->db->beginTransaction();
            $model->guardar((int)$asignacionId, $dictamen, $relativePath, basename($archivo['name']), $mime, (int)$archivo['size']);
            (new Auditoria($this->db))->registrar((int)$_SESSION['usuario_id'], 'evaluacion_empresa.guardada', 'asignaciones_fct', (int)$asignacionId, 'dictamen=' . $dictamen . ';tipo=' . $extension);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if (is_file($destination)) { unlink($destination); }
            throw $exception;
        }

        if (!empty($assignment['evaluacion_archivo_path'])) {
            $oldPath = fctStoragePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $assignment['evaluacion_archivo_path']);
            if (is_file($oldPath)) { unlink($oldPath); }
        }
        $notice = '';
        try {
            $coordinators = $this->db->query("SELECT email FROM usuarios WHERE rol = 'Coordinador' AND activo = 1 AND email IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
            if ($coordinators !== []) {
                (new EvaluationMailer())->send($coordinators, (string)$assignment['nombre_empresa'], trim((string)$assignment['nombre'] . ' ' . (string)$assignment['apellidos']), $dictamen);
                (new Auditoria($this->db))->registrar((int)$_SESSION['usuario_id'], 'evaluacion_empresa.notificada', 'asignaciones_fct', (int)$asignacionId, 'destinatarios=' . count($coordinators));
            } else {
                $notice = ' La evaluación se guardó, pero no hay coordinadores activos configurados.';
            }
        } catch (Throwable $exception) {
            error_log('Aviso de evaluación no enviado: ' . $exception->getMessage());
            $notice = ' La evaluación se guardó, pero no se pudo enviar el aviso al coordinador.';
        }
        header('Location: index.php?action=evaluaciones-empresa&guardada=1&aviso=' . rawurlencode($notice));
        exit;
    }

    private function failure(string $message, int $status = 422): never
    {
        header('Location: index.php?action=evaluaciones-empresa&error=' . rawurlencode($message));
        exit;
    }
}
