<?php

declare(strict_types=1);

require_once __DIR__ . '/../Models/DocumentacionInicio.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../Core/PdfDocumentGenerator.php';
require_once __DIR__ . '/../Core/PracticeDocumentsMailer.php';
require_once __DIR__ . '/../../config/storage.php';

final class DocumentacionInicioService
{
    public function __construct(private PDO $db)
    {
    }

    public function generarYNotificar(int $asignacionId, int $actorId): void
    {
        $model = new DocumentacionInicio($this->db);
        $data = $model->datosAsignacion($asignacionId);
        if ($data === null) throw new RuntimeException('No se encontró la asignación confirmada.');
        $root = fctStoragePath() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'documentacion-inicio' . DIRECTORY_SEPARATOR . $asignacionId;
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('No se pudo preparar la documentación privada.');
        chmod($root, 0700);
        $documents = [
            DocumentacionInicio::TYPE_GUIDE => [
                'name' => 'guia-inicio-practicas.pdf', 'title' => 'Guía de inicio de prácticas FCT',
                'lines' => ['Alumno: ' . $data['nombre'] . ' ' . $data['apellidos'], 'Empresa: ' . $data['nombre_empresa'], 'Ciclo: ' . $data['nombre_especialidad'], 'Periodo: ' . $data['fecha_inicio'] . ' a ' . $data['fecha_fin'], 'Tutor de empresa: ' . $data['tutor_empresa'], 'Conserva esta guía y consulta las indicaciones del centro.'],
            ],
            DocumentacionInicio::TYPE_PLAN => [
                'name' => 'plan-formativo.pdf', 'title' => 'Plan formativo de prácticas FCT',
                'lines' => ['Alumno: ' . $data['nombre'] . ' ' . $data['apellidos'], 'Empresa: ' . $data['nombre_empresa'], 'Oferta: ' . ($data['titulo'] ?: 'Prácticas FCT'), 'Ciclo formativo: ' . $data['nombre_especialidad'], 'Periodo formativo: ' . $data['fecha_inicio'] . ' a ' . $data['fecha_fin'], 'El seguimiento se realizará conforme al plan acordado por el centro y la empresa.'],
            ],
        ];
        $paths = [];
        try {
            $this->db->beginTransaction();
            foreach ($documents as $type => $document) {
                $filename = bin2hex(random_bytes(20)) . '.pdf';
                $relative = 'uploads/documentacion-inicio/' . $asignacionId . '/' . $filename;
                $absolute = $root . DIRECTORY_SEPARATOR . $filename;
                if (file_put_contents($absolute, PdfDocumentGenerator::create($document['title'], $document['lines']), LOCK_EX) === false) throw new RuntimeException('No se pudo generar el PDF de inicio.');
                chmod($absolute, 0600);
                $model->guardar($asignacionId, (int)$data['alumno_id'], $type, $relative, $document['name']);
                $paths[] = $absolute;
            }
            (new Auditoria($this->db))->registrar($actorId, 'documentacion_inicio.generada', 'asignaciones_fct', $asignacionId, 'documentos=guia_inicio,plan_formativo');
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            foreach ($paths as $path) if (is_file($path)) unlink($path);
            throw $exception;
        }
        $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
        (new PracticeDocumentsMailer())->send($data['email'], $data['nombre'] . ' ' . $data['apellidos'], $appUrl . '/index.php?action=documentacion-inicio');
        (new Auditoria($this->db))->registrar($actorId, 'documentacion_inicio.notificada', 'asignaciones_fct', $asignacionId);
    }
}
