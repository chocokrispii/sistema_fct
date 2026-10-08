<?php

require_once __DIR__ . '/../../config/database.php';

/** Fallos de login. Cinco contra la misma cuenta desde el mismo equipo bloquean 15 minutos. */
class IntentoLogin
{
    public const VENTANA_MINUTOS = 15;
    public const MAX_POR_EMAIL_E_IP = 5;
    public const MAX_POR_IP = 20;
    public const MAX_POR_EMAIL = 30;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
    }

    public function estaBloqueado(string $email, string $ip, DateTimeImmutable $ahora): bool
    {
        $desde = $ahora->modify('-' . self::VENTANA_MINUTOS . ' minutes')->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS por_email,
                    COALESCE(SUM(ip = :ip_par), 0) AS por_email_e_ip,
                    (SELECT COUNT(*) FROM intentos_login WHERE ip = :ip AND fecha >= :desde_ip) AS por_ip
             FROM intentos_login WHERE email = :email AND fecha >= :desde_email'
        );
        $stmt->execute([
            'ip_par' => $ip,
            'ip' => $ip,
            'desde_ip' => $desde,
            'email' => mb_substr($email, 0, 100),
            'desde_email' => $desde,
        ]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$fila['por_email_e_ip'] >= self::MAX_POR_EMAIL_E_IP
            || (int)$fila['por_ip'] >= self::MAX_POR_IP
            || (int)$fila['por_email'] >= self::MAX_POR_EMAIL;
    }

    public function registrarFallo(string $email, string $ip, DateTimeImmutable $ahora): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO intentos_login (email, ip, fecha) VALUES (:email, :ip, :fecha)'
        );
        $stmt->execute([
            'email' => mb_substr($email, 0, 100),
            'ip' => $ip,
            'fecha' => $ahora->format('Y-m-d H:i:s'),
        ]);
        $borrar = $this->db->prepare('DELETE FROM intentos_login WHERE fecha < :limite');
        $borrar->execute([
            'limite' => $ahora->modify('-24 hours')->format('Y-m-d H:i:s'),
        ]);
    }

    public function limpiar(string $email): void
    {
        $stmt = $this->db->prepare('DELETE FROM intentos_login WHERE email = :email');
        $stmt->execute(['email' => mb_substr($email, 0, 100)]);
    }
}
