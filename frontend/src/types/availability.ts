import type { Turno } from './domain';

export interface AvailabilityDayState {
    day: number;
    name: string;
    active: boolean;
    start: string;
    end: string;
}

export interface AvailabilityIslandState {
    days: AvailabilityDayState[];
    csrf: string;
    esIndiferente: boolean;
}

export interface SavedAvailabilityDay {
    dia_semana: number;
    dia: string;
    hora_inicio: string;
    hora_fin: string;
}

export interface SavedAvailabilitySchedule {
    fecha_inicio: string;
    fecha_fin: string;
    es_indiferente: boolean;
    dias: SavedAvailabilityDay[];
}

export interface AvailabilityDayPayload {
    activo: boolean;
    hora_inicio: string;
    hora_fin: string;
}

export interface AvailabilityUpdatePayload {
    fecha_inicio: string;
    fecha_fin: string;
    turno: Turno;
    es_indiferente: boolean;
    horarios: Record<number, AvailabilityDayPayload>;
}

export interface AvailabilityUpdateResult {
    message: string;
    savedSchedule: SavedAvailabilitySchedule;
}
