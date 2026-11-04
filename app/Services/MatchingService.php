<?php

declare(strict_types=1);

final class MatchingService
{
    public function __construct(
        private int $toleranceDays = 5,
        private int $minimumOverlapDays = 1,
        private float $minimumSchedulePercent = 80.0
    ) {
        if ($toleranceDays < 0 || $toleranceDays > 30 || $minimumOverlapDays < 1
            || $minimumSchedulePercent < 0 || $minimumSchedulePercent > 100) {
            throw new InvalidArgumentException('La configuración del motor de matching no es válida.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::environmentInt('FCT_MATCH_DATE_TOLERANCE_DAYS', 5, 0, 30),
            self::environmentInt('FCT_MATCH_MIN_OVERLAP_DAYS', 1, 1, 366),
            self::environmentFloat('FCT_MATCH_MIN_SCHEDULE_PERCENT', 80.0, 0, 100)
        );
    }

    public function toleranceDays(): int
    {
        return $this->toleranceDays;
    }

    public function minimumOverlapDays(): int
    {
        return $this->minimumOverlapDays;
    }

    public function minimumSchedulePercent(): float
    {
        return $this->minimumSchedulePercent;
    }

    public function evaluate(
        string $offerStart,
        string $offerEnd,
        string $availabilityStart,
        string $availabilityEnd,
        array $offerSchedule,
        array $studentSchedule,
        bool $indifferentSchedule
    ): ?array {
        $offerStartDate = $this->date($offerStart);
        $offerEndDate = $this->date($offerEnd);
        $availableStartDate = $this->date($availabilityStart);
        $availableEndDate = $this->date($availabilityEnd);
        if ($offerStartDate > $offerEndDate || $availableStartDate > $availableEndDate || $offerSchedule === []) {
            return null;
        }

        $lateStartDays = max(0, (int)$offerStartDate->diff($availableStartDate)->format('%r%a'));
        $earlyEndDays = max(0, (int)$availableEndDate->diff($offerEndDate)->format('%r%a'));
        if ($lateStartDays > $this->toleranceDays || $earlyEndDays > $this->toleranceDays) {
            return null;
        }

        $adaptiveStart = $offerStartDate >= $availableStartDate ? $offerStartDate : $availableStartDate;
        $adaptiveEnd = $offerEndDate <= $availableEndDate ? $offerEndDate : $availableEndDate;
        if ($adaptiveStart > $adaptiveEnd) {
            return null;
        }
        $offerDays = (int)$offerStartDate->diff($offerEndDate)->days + 1;
        $overlapDays = (int)$adaptiveStart->diff($adaptiveEnd)->days + 1;
        if ($overlapDays < $this->minimumOverlapDays) {
            return null;
        }

        $schedulePercent = $indifferentSchedule
            ? 100.0
            : $this->scheduleCompatibility($offerSchedule, $studentSchedule);
        if ($schedulePercent < $this->minimumSchedulePercent) {
            return null;
        }
        $datePercent = min(100.0, ($overlapDays / max(1, $offerDays)) * 100);

        return [
            'compatibilidad_porcentaje' => (int)round(($datePercent * 0.6) + ($schedulePercent * 0.4)),
            'compatibilidad_fechas' => (int)round($datePercent),
            'compatibilidad_horaria' => (int)round($schedulePercent),
            'fecha_inicio_adaptada' => $adaptiveStart->format('Y-m-d'),
            'fecha_fin_adaptada' => $adaptiveEnd->format('Y-m-d'),
            'dias_solapados' => $overlapDays,
            'desfase_inicio_dias' => $lateStartDays,
            'desfase_fin_dias' => $earlyEndDays,
        ];
    }

    private function scheduleCompatibility(array $offerSchedule, array $studentSchedule): float
    {
        $scores = [];
        foreach ($offerSchedule as $offerShift) {
            $offerStart = $this->minutes((string)($offerShift['hora_inicio'] ?? ''));
            $offerEnd = $this->minutes((string)($offerShift['hora_fin'] ?? ''));
            $day = (int)($offerShift['dia_semana'] ?? 0);
            if ($day < 1 || $day > 7 || $offerStart === null || $offerEnd === null || $offerEnd <= $offerStart) {
                return 0.0;
            }
            $best = 0.0;
            foreach ($studentSchedule as $studentShift) {
                if ((int)($studentShift['dia_semana'] ?? 0) !== $day) {
                    continue;
                }
                $studentStart = $this->minutes((string)($studentShift['hora_inicio'] ?? ''));
                $studentEnd = $this->minutes((string)($studentShift['hora_fin'] ?? ''));
                if ($studentStart === null || $studentEnd === null || $studentEnd <= $studentStart) {
                    continue;
                }
                $overlap = max(0, min($offerEnd, $studentEnd) - max($offerStart, $studentStart));
                $best = max($best, ($overlap / ($offerEnd - $offerStart)) * 100);
            }
            $scores[] = $best;
        }
        return $scores === [] ? 0.0 : array_sum($scores) / count($scores);
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('El motor de matching recibió una fecha no válida.');
        }
        return $date;
    }

    private function minutes(string $value): ?int
    {
        if (preg_match('/\A([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?\z/', $value, $matches) !== 1) {
            return null;
        }
        return ((int)$matches[1] * 60) + (int)$matches[2];
    }

    private static function environmentInt(string $name, int $default, int $min, int $max): int
    {
        $raw = getenv($name);
        $value = $raw === false ? $default : filter_var($raw, FILTER_VALIDATE_INT);
        return is_int($value) && $value >= $min && $value <= $max ? $value : $default;
    }

    private static function environmentFloat(string $name, float $default, float $min, float $max): float
    {
        $raw = getenv($name);
        if ($raw === false || !is_numeric($raw)) {
            return $default;
        }
        $value = (float)$raw;
        return $value >= $min && $value <= $max ? $value : $default;
    }
}
