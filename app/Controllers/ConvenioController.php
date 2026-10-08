<?php

require_once __DIR__ . '/../Models/Convenio.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Models/Auditoria.php';

class ConvenioController
{
    private PDO $db;
    private Convenio $convenioModel;
    private Empresa $empresaModel;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
        $this->convenioModel = new Convenio($this->db);
        $this->empresaModel = new Empresa($this->db);
    }

    public function gestionarEmpresa(): void
    {
        $empresa = $this->empresaModel->obtenerPorUsuarioId((int)$_SESSION['usuario_id']);
        if ($empresa === null) {
            throw new RuntimeException('No se encontró el perfil de empresa.');
        }
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->guardarConvenio((int)$empresa['id']);
                header('Location: index.php?action=convenios&enviado=1');
                exit;
            } catch (Throwable $e) {
                error_log('Error al registrar convenio: ' . $e->getMessage());
                $error = $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : 'No se pudo registrar el convenio. Comprueba el número y los datos enviados.';
            }
        }

        View::render('convenios/empresa', [
            'empresa' => $empresa,
            'sedes' => $this->empresaModel->obtenerSedes((int)$empresa['id']),
            'convenios' => $this->convenioModel->listar((int)$empresa['id']),
            'error' => $error,
        ]);
    }

    private function guardarConvenio(int $empresaId): void
    {
        $numeroRaw = $_POST['numero_convenio'] ?? '';
        $sedeRaw = $_POST['sede_id'] ?? '';
        $firmaRaw = $_POST['fecha_firma'] ?? '';
        $inicioRaw = $_POST['fecha_inicio'] ?? '';
        $finRaw = $_POST['fecha_fin'] ?? '';
        if (
            !is_string($numeroRaw)
            || !is_string($sedeRaw)
            || !is_string($firmaRaw)
            || !is_string($inicioRaw)
            || !is_string($finRaw)
        ) {
            throw new InvalidArgumentException('Los datos del convenio no tienen un formato válido.');
        }
        $numero = trim($numeroRaw);
        $sede = $sedeRaw === ''
            ? null
            : filter_var($sedeRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($sede === false) {
            throw new InvalidArgumentException('Selecciona una sede válida.');
        }
        $firma = $this->parseDate($firmaRaw);
        $inicio = $this->parseDate($inicioRaw);
        $fin = $this->parseDate($finRaw);
        $upload = $_FILES['archivo'] ?? null;

        if ($numero === '' || mb_strlen($numero) > 100 || $firma === null || $inicio === null || $fin === null) {
            throw new InvalidArgumentException('Completa un número de convenio y fechas válidas.');
        }
        if ($inicio > $fin || $firma > $inicio) {
            throw new InvalidArgumentException('La firma no puede ser posterior al inicio y la fecha final debe ser igual o posterior a la inicial.');
        }
        if ($sede !== null) {
            $belongs = $this->db->prepare('SELECT 1 FROM empresa_sedes WHERE id = :sede AND empresa_id = :empresa AND activa = 1');
            $belongs->execute(['sede' => $sede, 'empresa' => $empresaId]);
            if (!$belongs->fetchColumn()) {
                throw new InvalidArgumentException('La sede seleccionada no pertenece a tu empresa.');
            }
        }

        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Selecciona un PDF válido de hasta 10 MB.');
        }
        if (
            !is_int($upload['size'] ?? null)
            || !is_string($upload['tmp_name'] ?? null)
            || !is_string($upload['name'] ?? null)
            || $upload['size'] < 1
            || $upload['size'] > 10 * 1024 * 1024
            || !is_uploaded_file($upload['tmp_name'])
        ) {
            throw new InvalidArgumentException('El archivo excede el límite de 10 MB o no es una carga válida.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if ($mime !== 'application/pdf' || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'pdf') {
            throw new InvalidArgumentException('El documento debe ser un archivo PDF.');
        }

        $directory = dirname(__DIR__, 2) . '/storage/uploads/convenios';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento privado de convenios.');
        }
        $storedName = bin2hex(random_bytes(24)) . '.pdf';
        $destination = $directory . '/' . $storedName;
        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
            throw new RuntimeException('No se pudo guardar el archivo del convenio.');
        }

        $this->db->beginTransaction();
        try {
            $convenioId = $this->convenioModel->crear(
                $empresaId,
                $sede === null ? null : (int)$sede,
                $numero,
                $firma->format('Y-m-d'),
                $inicio->format('Y-m-d'),
                $fin->format('Y-m-d'),
                $storedName
            );
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'convenio.creado',
                'convenios',
                $convenioId,
                'estado=Pendiente'
            );
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (is_file($destination)) {
                unlink($destination);
            }
            throw $e;
        }
    }

    public function revisar(): void
    {
        View::render('convenios/revisar', ['convenios' => $this->convenioModel->listar(null, 'Pendiente')]);
    }

    public function resolver(): void
    {
        $id = filter_var($_POST['convenio_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $decision = $_POST['decision'] ?? '';
        $motivoRaw = $_POST['motivo'] ?? '';
        if (!is_string($motivoRaw)) {
            throw new InvalidArgumentException('El motivo de revisión no es válido.');
        }
        $motivo = trim($motivoRaw);
        if ($id === false || !in_array($decision, ['Aprobado', 'Rechazado'], true)) {
            throw new InvalidArgumentException('La decisión de revisión no es válida.');
        }
        if ($decision === 'Rechazado' && $motivo === '') {
            throw new InvalidArgumentException('Indica el motivo del rechazo.');
        }
        $this->db->beginTransaction();
        try {
            if (!$this->convenioModel->resolver((int)$id, $decision, $motivo, (int)$_SESSION['usuario_id'])) {
                throw new RuntimeException('El convenio ya fue revisado o no existe.');
            }
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'convenio.revisado',
                'convenios',
                (int)$id,
                'estado=' . $decision
            );
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        header('Location: index.php?action=revisar-convenios');
        exit;
    }

    public function descargar(): void
    {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $convenio = $id === false ? null : $this->convenioModel->obtener((int)$id);
        if ($convenio === null) {
            http_response_code(404);
            throw new RuntimeException('El documento solicitado no existe.');
        }
        if ($_SESSION['rol'] === 'Empresa') {
            $empresa = $this->empresaModel->obtenerPorUsuarioId((int)$_SESSION['usuario_id']);
            if ($empresa === null || (int)$empresa['id'] !== (int)$convenio['empresa_id']) {
                http_response_code(403);
                throw new RuntimeException('No tienes permiso para descargar este documento.');
            }
        }

        $base = realpath(dirname(__DIR__, 2) . '/storage/uploads/convenios');
        $file = $base === false ? false : realpath($base . DIRECTORY_SEPARATOR . basename($convenio['archivo_path']));
        if ($base === false || $file === false || dirname($file) !== $base || !is_file($file)) {
            throw new RuntimeException('No se encontró el archivo del convenio.');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: attachment; filename="convenio-' . (int)$convenio['id'] . '.pdf"');
        readfile($file);
        exit;
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = DateTimeImmutable::getLastErrors();
        return $date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ? $date
            : null;
    }
}
