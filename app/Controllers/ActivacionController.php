<?php

class ActivacionController
{
    public function activar(): void
    {
        $token = $_GET['token'] ?? $_POST['token'] ?? '';
        if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            $this->render('El enlace de activación no es válido.');
            return;
        }

        $db = getPDOConnection();
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
                $stmt = $db->prepare(
                    'SELECT t.usuario_id, t.expires_at, t.used_at
                     FROM student_activation_tokens t
                     WHERE t.token_hash = :token_hash
                     FOR UPDATE'
                );
                $stmt->execute(['token_hash' => hash('sha256', $token)]);
                $activation = $stmt->fetch(PDO::FETCH_ASSOC);
                if (
                    !is_array($activation)
                    || $activation['used_at'] !== null
                    || strtotime($activation['expires_at']) <= time()
                ) {
                    throw new DomainException('El enlace ya se utilizó o ha caducado. Contacta con el centro para solicitar uno nuevo.');
                }

                $updateUser = $db->prepare(
                    'UPDATE usuarios
                     SET password_hash = :password_hash, activo = 1
                     WHERE id = :usuario_id AND rol = \'Alumno\' AND activo = 0'
                );
                $updateUser->execute([
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'usuario_id' => (int)$activation['usuario_id'],
                ]);
                if ($updateUser->rowCount() !== 1) {
                    throw new DomainException('La cuenta ya está activada o no está disponible.');
                }

                $markUsed = $db->prepare(
                    'UPDATE student_activation_tokens SET used_at = CURRENT_TIMESTAMP WHERE token_hash = :token_hash'
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
                $this->render($exception->getMessage(), $token);
                return;
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Error al activar cuenta de alumno: ' . $exception->getMessage());
                $this->render('No se pudo activar la cuenta. Inténtalo más tarde o contacta con el centro.', $token);
                return;
            }
        }

        $stmt = $db->prepare(
            'SELECT 1 FROM student_activation_tokens
             WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > CURRENT_TIMESTAMP
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        if (!$stmt->fetchColumn()) {
            $this->render('El enlace ya se utilizó o ha caducado. Contacta con el centro para solicitar uno nuevo.');
            return;
        }

        $this->render('', $token);
    }

    private function render(string $error, string $token = ''): void
    {
        View::render('auth/activation', [
            'error' => $error,
            'token' => $token,
            'activated' => false,
        ]);
    }
}
