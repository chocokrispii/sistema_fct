<?php

declare(strict_types=1);

final class DevelopmentPreviewController
{
    public static function isEnabled(): bool
    {
        return in_array(getenv('APP_ENV'), ['development', 'local'], true);
    }

    public function availability(): void
    {
        if (!self::isEnabled()) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $days = [
            ['day' => 1, 'name' => 'Lunes', 'active' => true, 'start' => '09:00', 'end' => '14:00'],
            ['day' => 2, 'name' => 'Martes', 'active' => true, 'start' => '09:00', 'end' => '14:00'],
            ['day' => 3, 'name' => 'Miércoles', 'active' => true, 'start' => '10:00', 'end' => '15:00'],
            ['day' => 4, 'name' => 'Jueves', 'active' => false, 'start' => '', 'end' => ''],
            ['day' => 5, 'name' => 'Viernes', 'active' => true, 'start' => '09:00', 'end' => '13:00'],
            ['day' => 6, 'name' => 'Sábado', 'active' => false, 'start' => '', 'end' => ''],
            ['day' => 7, 'name' => 'Domingo', 'active' => false, 'start' => '', 'end' => ''],
        ];
        $startDate = (new DateTimeImmutable('today'))->modify('+14 days');

        View::render('dev/preview-disponibilidad', [
            'state' => [
                'days' => $days,
                'csrf' => CsrfMiddleware::token(),
                'esIndiferente' => false,
            ],
            'fechaInicio' => $startDate->format('Y-m-d'),
            'fechaFin' => $startDate->modify('+60 days')->format('Y-m-d'),
        ]);
    }

    public function previewPublicarOferta(): void
    {
        if (!self::isEnabled()) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $startDate = (new DateTimeImmutable('today'))->modify('+14 days');
        View::render('dev/preview-publicar-oferta', [
            'empresa' => ['nombre_empresa' => 'Empresa de ejemplo'],
            'csrf' => CsrfMiddleware::token(),
            'especialidades' => [
                ['id' => 'daw', 'nombre_especialidad' => 'Desarrollo de Aplicaciones Web (DAW)'],
                ['id' => 'dam', 'nombre_especialidad' => 'Desarrollo de Aplicaciones Multiplataforma (DAM)'],
            ],
            'fechaInicio' => $startDate->format('Y-m-d'),
            'fechaFin' => $startDate->modify('+60 days')->format('Y-m-d'),
        ]);
    }
}
