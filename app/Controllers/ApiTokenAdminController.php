<?php

declare(strict_types=1);

require_once __DIR__ . '/../Core/ApiToken.php';
require_once __DIR__ . '/../Models/Auditoria.php';

final class ApiTokenAdminController
{
    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= getPDOConnection();
    }

    public function gestionar(): void
    {
        $plainTextToken = null;
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['token_action'] ?? '';
            if ($action === 'create') {
                $plainTextToken = $this->crearToken($error);
            } elseif ($action === 'revoke') {
                $this->revocarToken($error);
            } else {
                throw new InvalidArgumentException('La acción de gestión de tokens no es válida.');
            }
        }

        $users = $this->db->query(
            "SELECT id, email, rol FROM usuarios WHERE activo = 1
             ORDER BY rol, email"
        )->fetchAll(PDO::FETCH_ASSOC);
        $tokens = $this->db->query(
            'SELECT t.id, t.name, t.created_at, t.last_used_at, u.email, u.rol,
                    t.revoked_at
             FROM api_access_tokens t
             INNER JOIN usuarios u ON u.id = t.user_id
             ORDER BY t.created_at DESC, t.id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);

        View::render('admin/api_tokens', [
            'users' => $users,
            'tokens' => $tokens,
            'plainTextToken' => $plainTextToken,
            'error' => $error,
        ]);
    }

    private function crearToken(string &$error): ?string
    {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $nameRaw = $_POST['name'] ?? '';
        if ($userId === false || !is_string($nameRaw)) {
            $error = 'Selecciona una cuenta y un nombre válidos.';
            return null;
        }

        $name = trim($nameRaw);
        if ($name === '' || mb_strlen($name) > 100) {
            $error = 'El nombre del token debe tener entre 1 y 100 caracteres.';
            return null;
        }

        $user = $this->db->prepare('SELECT id FROM usuarios WHERE id = :id AND activo = 1');
        $user->execute(['id' => $userId]);
        if (!$user->fetchColumn()) {
            $error = 'La cuenta seleccionada no existe o está desactivada.';
            return null;
        }

        $token = ApiToken::issue();
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO api_access_tokens (user_id, name, token_hash, created_by)
                 VALUES (:user_id, :name, :token_hash, :created_by)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'name' => $name,
                'token_hash' => $token['hash'],
                'created_by' => (int)$_SESSION['usuario_id'],
            ]);
            $tokenId = (int)$this->db->lastInsertId();
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'api_token.creado',
                'api_access_tokens',
                $tokenId,
                'usuario_id=' . (int)$userId . '; nombre=' . $name,
                'creación'
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return $token['plainText'];
    }

    private function revocarToken(string &$error): void
    {
        $id = filter_var($_POST['token_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $error = 'Selecciona un token válido para revocar.';
            return;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE api_access_tokens SET revoked_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND revoked_at IS NULL'
            );
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() !== 1) {
                $this->db->rollBack();
                $error = 'El token no existe o ya fue revocado.';
                return;
            }
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'api_token.revocado',
                'api_access_tokens',
                (int)$id,
                '',
                'revocación'
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
