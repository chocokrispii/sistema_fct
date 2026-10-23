<?php

class ActivacionController
{
    public function activar(): void
    {
        $token = $_GET['token'] ?? $_POST['token'] ?? '';
        if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            if (isset($_GET['solicitud']) && $_GET['solicitud'] === '1') {
                $this->render('', '', false, true);
                return;
            }
            $this->render('El enlace ha caducado o no es válido.', '', false, true);
            return;
        }

        $db = getPDOConnection();
        $activation = $this->findActivation($db, $token);
        if (
            !is_array($activation)
            || $activation['used_at'] !== null
            || strtotime($activation['expires_at']) <= time()
        ) {
            $this->render('El enlace ha caducado o no es válido.', '', false, true);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $password = $_POST['password'] ?? null;
            $confirmation = $_POST['password_confirmation'] ?? null;
            if (!is_string($password) || !is_string($confirmation)) {
                $this->render('Los datos de la contraseña no son válidos.', $token);
                return;
            }
            if (strlen($password) < 12 || strlen($password) > 72 || trim($password) === '') {
                $this->render('La contraseña debe tener entre 12 y 72 caracteres.', $token);
                return;
            }
            if (!hash_equals($password, $confirmation)) {
                $this->render('Las contraseñas no coinciden.', $token);
                return;
            }

            $db->beginTransaction();
            try {
                $activation = $this->findActivation($db, $token, true);
                if (
                    !is_array($activation)
                    || $activation['used_at'] !== null
                    || strtotime($activation['expires_at']) <= time()
                ) {
                    throw new DomainException('El enlace ya se utilizó o ha caducado. Contacta con el centro para solicitar uno nuevo.');
                }

                $staffActivation = $activation['type'] === 'staff';
                $roleFilter = $staffActivation
                    ? "rol IN ('Administrador', 'Coordinador', 'Observador')"
                    : "rol = 'Alumno'";
                $updateUser = $db->prepare(
                    'UPDATE usuarios
                     SET password_hash = :password_hash, activo = 1
                     WHERE id = :usuario_id AND ' . $roleFilter . ' AND activo = 0'
                );
                $updateUser->execute([
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'usuario_id' => (int)$activation['usuario_id'],
                ]);
                if ($updateUser->rowCount() !== 1) {
                    throw new DomainException('La cuenta ya está activada o no está disponible.');
                }

                $table = $staffActivation ? 'staff_activation_tokens' : 'student_activation_tokens';
                $markUsed = $db->prepare(
                    'UPDATE ' . $table . ' SET used_at = CURRENT_TIMESTAMP WHERE token_hash = :token_hash'
                );
                $markUsed->execute(['token_hash' => hash('sha256', $token)]);
                $db->commit();
                View::render('auth/activation', [
                    'error' => '',
                    'token' => '',
                    'activated' => true,
                ]);
                return;
            } catch (DomainException $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $this->render('El enlace ha caducado o no es válido.', '', false, true);
                return;
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Error al activar cuenta: ' . $exception->getMessage());
                $this->render('No se pudo activar la cuenta. Inténtalo más tarde o contacta con el centro.', $token);
                return;
            }
        }

        $this->render('', $token);
    }

    private function findActivation(PDO $db, string $token, bool $forUpdate = false): ?array
    {
        $suffix = $forUpdate ? ' FOR UPDATE' : '';
        $tokenHash = hash('sha256', $token);
        foreach (['student' => 'student_activation_tokens', 'staff' => 'staff_activation_tokens'] as $type => $table) {
            $stmt = $db->prepare(
                'SELECT usuario_id, expires_at, used_at
                 FROM ' . $table . ' WHERE token_hash = :token_hash LIMIT 1' . $suffix
            );
            $stmt->execute(['token_hash' => $tokenHash]);
            $activation = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($activation)) {
                $activation['type'] = $type;
                return $activation;
            }
        }
        return null;
    }

    private function render(
        string $error,
        string $token = '',
        bool $activated = false,
        bool $showResendRequest = false
    ): void {
        View::render('auth/activation', [
            'error' => $error,
            'token' => $token,
            'activated' => $activated,
            'showResendRequest' => $showResendRequest,
            'requestSent' => isset($_GET['solicitud']) && $_GET['solicitud'] === '1',
        ]);
    }
}
