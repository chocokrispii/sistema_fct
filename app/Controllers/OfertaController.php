<?php
// app/Controllers/OfertaController.php

require_once __DIR__ . '/../Models/Oferta.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Models/Especialidad.php';

class OfertaController {
    private PDO $db;
    private Oferta $ofertaModel;
    private Empresa $empresaModel;
    private Especialidad $especialidadModel;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
        $this->ofertaModel = new Oferta($this->db);
        $this->empresaModel = new Empresa($this->db);
        $this->especialidadModel = new Especialidad($this->db);
    }

    public function listar(): void {
        $ofertas = $this->ofertaModel->obtenerTodas(true);

        echo '<h2>Ofertas de FCT disponibles</h2>';
        $this->imprimirTabla($ofertas, true);
    }

    public function publicar(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (($_SESSION['rol'] ?? '') !== 'Empresa') {
            header('Location: index.php?action=login');
            exit;
        }

        $empresa = $this->empresaModel->obtenerPorUsuarioId((int)$_SESSION['usuario_id']);
        if ($empresa === null) {
            echo '<p style="color:red;">Tu usuario no tiene un perfil de empresa asociado.</p>';
            return;
        }
        $empresaId = (int)$empresa['id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $error = $this->procesarPublicacion($empresaId);
            if ($error === null) {
                header('Location: index.php?action=publicar-oferta&publicada=1');
                exit;
            }
            echo '<p style="color:red;">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
        } elseif (isset($_GET['publicada'])) {
            echo '<p style="color:green;">Oferta publicada correctamente.</p>';
        }

        $this->mostrarFormulario($empresa['nombre_empresa']);

        echo '<h3>Mis ofertas publicadas</h3>';
        $this->imprimirTabla($this->ofertaModel->obtenerPorEmpresa($empresaId), false);
    }

    /** Devuelve el mensaje de error o null si la oferta se ha creado. */
    private function procesarPublicacion(int $empresaId): ?string {
        $especialidadId = filter_var($_POST['especialidad_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $plazas = filter_var($_POST['plazas'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $fechaInicio = $this->parsearFecha($_POST['fecha_inicio'] ?? '');
        $fechaFin = $this->parsearFecha($_POST['fecha_fin'] ?? '');

        if ($especialidadId === false || !$this->especialidadModel->existe($especialidadId)) {
            return 'Selecciona una especialidad válida.';
        }
        if ($plazas === false) {
            return 'El número de plazas debe ser un entero entre 1 y 100.';
        }
        if ($fechaInicio === null || $fechaFin === null) {
            return 'Las fechas de inicio y fin deben tener un formato válido.';
        }
        if ($fechaInicio < new DateTimeImmutable('today')) {
            return 'La fecha de inicio no puede ser anterior a hoy.';
        }
        if ($fechaFin <= $fechaInicio) {
            return 'La fecha de fin debe ser posterior a la fecha de inicio.';
        }

        $ofertaId = $this->ofertaModel->crearOferta(
            $empresaId,
            $especialidadId,
            $plazas,
            $fechaInicio->format('Y-m-d'),
            $fechaFin->format('Y-m-d')
        );

        return $ofertaId > 0 ? null : 'No se pudo publicar la oferta.';
    }

    private function parsearFecha(string $valor): ?DateTimeImmutable {
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', trim($valor));
        $errores = DateTimeImmutable::getLastErrors();
        if (!$fecha || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))) {
            return null;
        }
        return $fecha;
    }

    private function mostrarFormulario(string $nombreEmpresa): void {
        $especialidades = $this->especialidadModel->obtenerTodas();
        ?>
        <h2>Publicar plazas de FCT — <?= htmlspecialchars($nombreEmpresa, ENT_QUOTES, 'UTF-8') ?></h2>
        <form method="POST" action="index.php?action=publicar-oferta">
            <label>Especialidad:</label>
            <?php require __DIR__ . '/../Views/partials/select_especialidades.php'; ?>
            <label>Número de plazas:</label>
            <input type="number" name="plazas" min="1" max="100" value="1" required>
            <label>Fecha de inicio:</label>
            <input type="date" name="fecha_inicio" required>
            <label>Fecha de fin:</label>
            <input type="date" name="fecha_fin" required>
            <button type="submit">Publicar oferta</button>
        </form>
        <?php
    }

    private function imprimirTabla(array $ofertas, bool $mostrarEmpresa): void {
        if ($ofertas === []) {
            echo '<p>No hay ofertas para mostrar.</p>';
            return;
        }
        ?>
        <table>
            <thead>
                <tr>
                    <?php if ($mostrarEmpresa): ?><th>Empresa</th><?php endif; ?>
                    <th>Especialidad</th>
                    <th>Plazas</th>
                    <th>Inicio</th>
                    <th>Fin</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ofertas as $oferta): ?>
                    <tr>
                        <?php if ($mostrarEmpresa): ?>
                            <td><?= htmlspecialchars($oferta['nombre_empresa'], ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($oferta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)$oferta['plazas_cuantas'] ?></td>
                        <td><?= htmlspecialchars($oferta['fecha_inicio'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($oferta['fecha_fin'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
