<?php

declare(strict_types=1);

require_once __DIR__ . '/../Models/Alumno.php';
require_once __DIR__ . '/../Models/DocumentacionCierre.php';
require_once __DIR__ . '/../Core/FileScanner.php';
require_once __DIR__ . '/../Core/JsonResponse.php';
require_once __DIR__ . '/../../config/storage.php';

final class DocumentacionAlumnoController
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const ALLOWED_TYPES = [DocumentacionCierre::TYPE_MEMORIA, DocumentacionCierre::TYPE_HOJA_FIRMAS];

    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= getPDOConnection();
    }

    public function index(): void
    {
        $model = new DocumentacionCierre($this->db);
        View::render('alumnos/documentacion-cierre', [
            'asignaciones' => $model->listarPorUsuario((int)$_SESSION['usuario_id']),
            'csrfToken' => CsrfMiddleware::token(),
            'error' => is_string($_GET['error'] ?? null) ? $_GET['error'] : '',
            'subido' => isset($_GET['subido']),
        ]);
    }

    public function subir(): void
    {
        $json = JsonResponse::wantsJson();
        $asignacionId = filter_var($_POST['asignacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $tipo = $_POST['tipo_documento'] ?? '';
        $archivo = $_FILES['documento'] ?? null;
        if ($asignacionId === false || !is_string($tipo) || !in_array($tipo, self::ALLOWED_TYPES, true)) {
            $this->failure('Selecciona una práctica y un tipo de documento válido.', $json, 422);
        }
        if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($archivo['name'] ?? null) || !is_string($archivo['tmp_name'] ?? null)
            || !is_uploaded_file($archivo['tmp_name']) || !is_int($archivo['size'] ?? null)
            || $archivo['size'] < 1 || $archivo['size'] > self::MAX_BYTES) {
            $this->failure('El archivo debe pesar entre 1 byte y 10 MB.', $json, 422);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        $allowedMime = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array($mime, $allowedMime, true)) {
            $this->failure('Solo se admiten PDF, JPG o PNG.', $json, 422);
        }
        FileScanner::scan($archivo['tmp_name']);

        $usuarioId = (int)$_SESSION['usuario_id'];
        $ownership = $this->db->prepare(
            "SELECT af.id, a.id AS alumno_id
             FROM asignaciones_fct af INNER JOIN alumnos a ON a.id = af.alumno_id
             WHERE af.id = :asignacion_id AND a.usuario_id = :usuario_id AND af.estado = 'Cerrada' LIMIT 1"
        );
        $ownership->execute(['asignacion_id' => (int)$asignacionId, 'usuario_id' => $usuarioId]);
        $assignment = $ownership->fetch(PDO::FETCH_ASSOC);
        if (!$assignment) {
            $this->failure('La práctica no está disponible para entregar documentación.', $json, 403);
        }

        $extension = $mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : 'jpg');
        $relativePath = 'uploads/documentacion-cierre/' . $usuarioId . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
        $storage = fctStoragePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, dirname($relativePath));
        if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
            $this->failure('No se pudo preparar el almacenamiento privado.', $json, 500);
        }
        chmod($storage, 0700);
        $destination = fctStoragePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!move_uploaded_file($archivo['tmp_name'], $destination) || !chmod($destination, 0600)) {
            $this->failure('No se pudo guardar el documento.', $json, 500);
        }

        $model = new DocumentacionCierre($this->db);
        $old = $model->obtenerAnterior((int)$assignment['alumno_id'], (int)$asignacionId, $tipo);
        try {
            $this->db->beginTransaction();
            $model->guardar((int)$assignment['alumno_id'], (int)$asignacionId, $tipo, $relativePath, basename($archivo['name']), $mime, (int)$archivo['size']);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if (is_file($destination)) unlink($destination);
            throw $exception;
        }

        if ($old !== null) {
            $oldPath = fctStoragePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $old['archivo_path']);
            if (is_file($oldPath)) unlink($oldPath);
        }
        if ($json) {
            JsonResponse::success(['tipo_documento' => $tipo, 'nombre_original' => basename($archivo['name'])]);
            return;
        }
        header('Location: index.php?action=documentacion-cierre&subido=1');
        exit;
    }

    private function failure(string $message, bool $json, int $status): never
    {
        if ($json) {
            JsonResponse::failure($message, $status);
            exit;
        }
        header('Location: index.php?action=documentacion-cierre&error=' . rawurlencode($message));
        exit;
    }
}
