<?php

declare(strict_types=1);

final class MatchingService
{
    private ?array $offerPeriodCache = null;

    public function __construct(
        private int $minimumPracticeDays = 1,
        private float $minimumSchedulePercent = 80.0,
        private int $toleranceDays = 5,
        private int $minimumOverlapDays = 1
    ) {
        if ($minimumPracticeDays < 1 || $minimumPracticeDays > 366
            || $minimumSchedulePercent < 0 || $minimumSchedulePercent > 100
            || $toleranceDays < 0 || $toleranceDays > 30
            || $minimumOverlapDays < 1 || $minimumOverlapDays > 366) {
            throw new InvalidArgumentException('La configuración del motor de matching no es válida.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            minimumPracticeDays: self::environmentInt('FCT_MATCH_MIN_PRACTICE_DAYS', 1, 1, 366),
            minimumSchedulePercent: self::environmentFloat('FCT_MATCH_MIN_SCHEDULE_PERCENT', 80.0, 0, 100),
            toleranceDays: self::environmentInt('FCT_MATCH_DATE_TOLERANCE_DAYS', 5, 0, 30),
            minimumOverlapDays: self::environmentInt('FCT_MATCH_MIN_OVERLAP_DAYS', 1, 1, 366)
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

    public function minimumPracticeDays(): int
    {
        return $this->minimumPracticeDays;
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
        $offerPeriod = $this->offerPeriodStats($offerStart, $offerEnd, $offerSchedule, $offerStartDate, $offerEndDate);
        $possiblePeriod = $this->scheduledPeriodStats($adaptiveStart, $adaptiveEnd, $offerSchedule);
        $offerHours = $offerPeriod['hours'];
        $possibleHours = $possiblePeriod['hours'];
        $offerPracticeDays = $offerPeriod['days'];
        $minimumHours = $offerPracticeDays > 0
            ? ($offerHours / $offerPracticeDays) * $this->minimumPracticeDays
            : 0.0;
        $possiblePracticeDays = $possiblePeriod['days'];
        if ($possiblePracticeDays < $this->minimumPracticeDays || $possibleHours < $minimumHours) {
            error_log(sprintf(
                'Matching descartado: tiempo restante insuficiente (días de prácticas %d/%d; horas posibles %.2f/%.2f).',
                $possiblePracticeDays,
                $this->minimumPracticeDays,
                $possibleHours,
                $minimumHours
            ));
            return null;
        }

        $schedulePercent = $indifferentSchedule
            ? 100.0
            : $this->scheduleCompatibility($offerSchedule, $studentSchedule);
        if ($schedulePercent < $this->minimumSchedulePercent) {
            return null;
        }
        $datePercent = $offerHours > 0
            ? min(100.0, ($possibleHours / $offerHours) * 100)
            : min(100.0, ($overlapDays / max(1, $offerDays)) * 100);

        return [
            'compatibilidad_porcentaje' => (int)round(($datePercent * 0.6) + ($schedulePercent * 0.4)),
            'compatibilidad_fechas' => (int)round($datePercent),
            'compatibilidad_horaria' => (int)round($schedulePercent),
            'fecha_inicio_adaptada' => $adaptiveStart->format('Y-m-d'),
            'fecha_fin_adaptada' => $adaptiveEnd->format('Y-m-d'),
            'dias_solapados' => $overlapDays,
            'dias_practica_posibles' => $possiblePracticeDays,
            'horas_posibles' => round($possibleHours, 2),
            'horas_minimas_requeridas' => round($minimumHours, 2),
            'desfase_inicio_dias' => $lateStartDays,
            'desfase_fin_dias' => $earlyEndDays,
        ];
    }

    private function offerPeriodStats(
        string $offerStart,
        string $offerEnd,
        array $offerSchedule,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        if ($this->offerPeriodCache !== null
            && $this->offerPeriodCache['start'] === $offerStart
            && $this->offerPeriodCache['end'] === $offerEnd
            && $this->offerPeriodCache['schedule'] === $offerSchedule) {
            return $this->offerPeriodCache['stats'];
        }

        $stats = $this->scheduledPeriodStats($start, $end, $offerSchedule);
        $this->offerPeriodCache = [
            'start' => $offerStart,
            'end' => $offerEnd,
            'schedule' => $offerSchedule,
            'stats' => $stats,
        ];
        return $stats;
    }

    private function scheduledPeriodStats(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $schedule
    ): array {
        $hours = 0.0;
        $hoursByWeekday = [];
        foreach ($schedule as $shift) {
            $day = (int)($shift['dia_semana'] ?? 0);
            $startMinutes = $this->minutes((string)($shift['hora_inicio'] ?? ''));
            $endMinutes = $this->minutes((string)($shift['hora_fin'] ?? ''));
            if ($day < 1 || $day > 7 || $startMinutes === null || $endMinutes === null || $endMinutes <= $startMinutes) {
                continue;
            }
            $hoursByWeekday[$day] = ($hoursByWeekday[$day] ?? 0) + $endMinutes - $startMinutes;
        }
        $practiceDays = 0;
        foreach ($hoursByWeekday as $day => $weekdayMinutes) {
            $occurrences = $this->weekdayOccurrences($start, $end, (int)$day);
            $practiceDays += $occurrences;
            $hours += ($occurrences * $weekdayMinutes) / 60;
        }
        return ['hours' => $hours, 'days' => $practiceDays];
    }

    private function weekdayOccurrences(DateTimeImmutable $start, DateTimeImmutable $end, int $weekday): int
    {
        $days = (int)$start->diff($end)->days + 1;
        $count = intdiv($days, 7);
        $remainder = $days % 7;
        $startWeekday = (int)$start->format('N');
        for ($offset = 0; $offset < $remainder; $offset++) {
            if ((($startWeekday - 1 + $offset) % 7) + 1 === $weekday) {
                $count++;
            }
        }
        return $count;
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
