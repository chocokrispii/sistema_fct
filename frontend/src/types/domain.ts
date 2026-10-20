export type Rol =
    | 'Alumno'
    | 'Empresa'
    | 'Coordinador'
    | 'Observador'
    | 'Administrador';

export interface Usuario {
    id: number;
    email: string;
    rol: Rol;
    activo: boolean;
}

export type EstadoOferta = 'Pendiente' | 'Aprobada' | 'Rechazada';
export type Turno = 'M' | 'T' | 'Indiferente';

export interface Oferta {
    id: number;
    empresa_id: number;
    especialidad_id: number;
    plazas_cuantas: number;
    fecha_inicio: string;
    fecha_fin: string;
    estado: EstadoOferta;
    turno: Turno;
    motivo_revision: string | null;
    nombre_empresa: string;
    nombre_especialidad: string;
}

export type EstadoConvenio = 'Pendiente' | 'Aprobado' | 'Rechazado';

export interface Convenio {
    id: number;
    empresa_id: number;
    especialidad_id: number | null;
    sede_id: number | null;
    numero_convenio: string;
    fecha_firma: string;
    fecha_inicio: string;
    fecha_fin: string;
    estado: EstadoConvenio;
    motivo_revision: string | null;
    revisado_por: number | null;
    revisado_en: string | null;
    creado_en: string;
    nombre_empresa?: string;
    nombre_sede?: string | null;
    nombre_especialidad?: string | null;
}

export type EstadoFct = 'Asignada' | 'Cerrada' | 'Cancelada';

export interface FCT {
    id: number;
    oferta_id: number;
    alumno_id: number;
    tutor_empresa: string;
    estado: EstadoFct;
    fecha_asignacion: string;
    fecha_cierre: string | null;
    asignado_por: number | null;
}

export interface FranjaDisponibilidad {
    dia_semana: number;
    hora_inicio: string;
    hora_fin: string;
}

export interface Disponibilidad {
    id: number;
    fecha_inicio: string;
    fecha_fin: string;
    turno: Turno;
    horario_semanal?: FranjaDisponibilidad[];
}
