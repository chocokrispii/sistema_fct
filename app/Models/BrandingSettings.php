<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

final class BrandingSettings
{
    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= getPDOConnection();
    }

    public function get(): array
    {
        $statement = $this->db->query(
            'SELECT primary_color, secondary_color, accent_color, logo_path, logo_mime
             FROM portal_branding
             WHERE id = 1'
        );
        $settings = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($settings)) {
            throw new RuntimeException('No se encontró la configuración de marca del portal.');
        }

        return $settings;
    }

    public function save(array $palette, ?string $logoPath, ?string $logoMime): ?string
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'SELECT logo_path FROM portal_branding WHERE id = 1 FOR UPDATE'
            );
            $statement->execute();
            $oldPath = $statement->fetchColumn();
            if ($oldPath === false || (!is_string($oldPath) && $oldPath !== null)) {
                throw new RuntimeException('No se pudo leer la configuración actual del portal.');
            }

            $statement = $this->db->prepare(
                'UPDATE portal_branding
                 SET primary_color = :primary, secondary_color = :secondary, accent_color = :accent,
                     logo_path = COALESCE(:logo_path, logo_path),
                     logo_mime = COALESCE(:logo_mime, logo_mime)
                 WHERE id = 1'
            );
            $statement->execute([
                'primary' => $palette['primary'],
                'secondary' => $palette['secondary'],
                'accent' => $palette['accent'],
                'logo_path' => $logoPath,
                'logo_mime' => $logoMime,
            ]);
            $this->db->commit();

            return $oldPath === false ? null : $oldPath;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function removeLogo(array $palette): ?string
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'SELECT logo_path FROM portal_branding WHERE id = 1 FOR UPDATE'
            );
            $statement->execute();
            $oldPath = $statement->fetchColumn();
            if ($oldPath === false || (!is_string($oldPath) && $oldPath !== null)) {
                throw new RuntimeException('No se pudo leer la configuración actual del portal.');
            }

            $statement = $this->db->prepare(
                'UPDATE portal_branding
                 SET primary_color = :primary, secondary_color = :secondary, accent_color = :accent,
                     logo_path = NULL, logo_mime = NULL
                 WHERE id = 1'
            );
            $statement->execute([
                'primary' => $palette['primary'],
                'secondary' => $palette['secondary'],
                'accent' => $palette['accent'],
            ]);
            $this->db->commit();

            return $oldPath === false ? null : $oldPath;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
