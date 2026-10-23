export interface ProfileUpdatePayload {
    email: string;
    nombre: string;
    apellidos: string;
    fecha_nacimiento: string;
    tipo_documento: 'NIF_NIE_DNI' | 'PASAPORTE';
    dni: string;
    nss: string;
    telefono: string;
    direccion: string;
    codigo_postal: string;
    tutor_nombre: string;
    tutor_dni: string;
    tutor_nss: string;
    tutor_direccion: string;
    tutor_telefono: string;
    tutor_correo: string;
}

export interface ProfileUpdateResult {
    message: string;
}
