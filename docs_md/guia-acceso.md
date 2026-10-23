Sistema FCT · Guía práctica

# Cómo acceder a la aplicación

Instrucciones sencillas para iniciar el entorno local, entrar según tu perfil y resolver los problemas más habituales.

**Dirección de acceso:**\
<span class="url">http://localhost:8080/index.php?action=login</span>

En el formulario escribe tu identificador fiscal y contraseña.

### Antes de empezar: iniciar la aplicación

1.  Abre Docker Desktop y espera a que indique que está iniciado.
2.  Abre PowerShell en la carpeta del proyecto.
3.  Inicia los servicios con `docker compose up -d`.
4.  Comprueba que la columna **STATUS** muestre los servicios principales como activos con `docker compose ps`.
5.  Abre la dirección de acceso de arriba en el navegador.

En la primera instalación puede ser necesario usar `docker compose up -d --build`. Para detener la aplicación sin borrar datos, usa `docker compose stop`; no uses opciones que eliminen volúmenes.

### ¿Qué identificador debo escribir?

| Perfil | Identificador de acceso | Contraseña |
|----|----|----|
| Alumno/a | NIF / NIE / DNI o pasaporte registrado por el centro | La que se eligió al activar la cuenta |
| Administrador, Coordinador u Observador | NIF / NIE / DNI asignado a la cuenta | La que se eligió al activar la cuenta |
| Empresa | NIF (Persona Jurídica) registrado en la cuenta | La que se eligió al activar la cuenta |

**Importante:** no se inicia sesión con correo electrónico. El alumnado y el personal usan NIF / NIE / DNI; el alumnado también puede usar pasaporte. Las empresas usan NIF (Persona Jurídica). No añadas espacios antes o después de los datos. La contraseña distingue mayúsculas y minúsculas. Guía de acceso · Uso local de desarrollo · No incluye contraseñas ni credenciales compartidas

01 · Alumnado y personal del centro

## Primer acceso

### Alumnado: cuenta creada por el centro

1.  El personal autorizado da de alta al alumno con su NIF/DNI/NIE, nombre, correo, ciclo y curso.
2.  El alumno recibe un enlace de activación por correo. El enlace es personal, de un solo uso y caduca a las 24 horas.
3.  Abre el enlace, define una contraseña nueva de entre 12 y 72 caracteres y confirma el formulario.
4.  Vuelve al inicio de sesión: escribe el NIF / NIE / DNI o pasaporte y la contraseña recién creada.

La cuenta no permite entrar hasta completar la activación. El centro no necesita ni debe pedir al alumno su contraseña.

### Personal del centro

El personal utiliza el NIF / NIE / DNI registrado para su cuenta, no el correo institucional. El administrador puede asignar o corregir estos identificadores desde **Usuarios**. También puede corregir el NIF (Persona Jurídica) de una empresa cuya cuenta ya existía. Si al personal le falta su identificador, debe facilitarlo al administrador antes de cerrar la sesión actual.

**Cuentas anteriores:** después de añadir el acceso por NIF, el administrador debe asignar su propio NIF y el de cada persona desde **Usuarios**. Mantén abierta la sesión administrativa hasta completar esa actualización. Si se perdió, el entorno local dispone del asistente CLI `docker compose exec -it app php scripts/asignar_nif_personal.php`. No se puede deducir el NIF desde el correo.

### Correo de prueba en el entorno local

En la configuración local, los correos de activación se capturan en Mailpit y no se envían a una bandeja externa. Para consultarlos en el mismo ordenador, abre:

<span class="url">http://localhost:8025</span>

Busca el mensaje por la dirección del alumno y sigue su enlace de activación. Como el enlace usa `localhost`, solo funciona en el ordenador donde se ejecuta la aplicación. Para que el alumnado acceda desde sus propios dispositivos se necesita un dominio HTTPS accesible y correo real configurado.

**Enlace caducado o no encontrado:** contacta con el centro para que compruebe el estado de la cuenta y te indique cómo obtener un enlace válido. No compartas el enlace ni lo publiques en grupos. Guía de acceso · Uso local de desarrollo · Los enlaces de activación son personales

02 · Empresas y recuperación

## Empresas y contraseñas

### Crear una cuenta de empresa

1.  Desde el inicio de sesión, selecciona **Registro empresa**.
2.  Completa correo, razón social, un NIF (Persona Jurídica) válido y dirección. Los datos de contacto adicionales ayudan al centro a identificar la solicitud.
3.  Revisa el correo y abre el enlace personal de activación, válido durante 24 horas.
4.  Elige y confirma una contraseña de entre 12 y 72 caracteres; después inicia sesión con el NIF (Persona Jurídica).

Si el correo o el NIF (Persona Jurídica) ya están asociados a otra cuenta, contacta con el administrador del centro en lugar de repetir el registro.

### ¿Has olvidado la contraseña?

1.  En la pantalla de acceso, pulsa **¿Has olvidado la contraseña?**.
2.  Escribe el correo asociado a la cuenta y envía la solicitud.
3.  El centro verificará la identidad y un Administrador o Coordinador facilitará un enlace de un solo uso.
4.  Abre el enlace y establece una contraseña nueva de entre 12 y 72 caracteres.

La solicitud no cambia la contraseña ni envía por sí misma un correo automático. El enlace de recuperación caduca a los 30 minutos.

### Recomendaciones para todas las personas

- Usa una contraseña privada y no la compartas por correo, chat ni capturas.
- No reutilices contraseñas personales o de otros servicios.
- Al terminar, cierra sesión si el equipo es compartido.
- No publiques enlaces de activación o recuperación: quien los tenga podría activar o cambiar la cuenta.

Guía de acceso · Uso local de desarrollo · Las solicitudes de recuperación requieren verificación del centro

03 · Solución de problemas

## Si no puedes entrar

| Qué ocurre | Qué hacer |
|----|----|
| La página no carga | Inicia Docker Desktop, ejecuta `docker compose up -d` desde la carpeta del proyecto y comprueba `docker compose ps`. Usa `http://localhost:8080`. |
| “Credenciales incorrectas” | Comprueba que el identificador corresponda a tu perfil: NIF / NIE / DNI o pasaporte del alumnado, NIF / NIE / DNI del personal, o NIF (Persona Jurídica) de empresa. El correo no es identificador de acceso. Borra el campo de contraseña y escríbela de nuevo con cuidado. |
| Cuenta desactivada o alumno recién dado de alta | El alumno debe activar primero su cuenta desde el enlace recibido. Si el enlace local no aparece en el correo, consulta Mailpit en `http://localhost:8025`. |
| Demasiados intentos | El acceso se bloquea temporalmente tras varios fallos. Espera 15 minutos y vuelve a intentarlo con los datos correctos. |
| El enlace de recuperación no funciona | Comprueba que no hayan pasado más de 30 minutos y que el enlace no se haya utilizado antes. Contacta con el centro para verificar la solicitud. |

**localhost solo sirve en este ordenador.**

Una persona en otro ordenador o en un móvil no puede abrir tu `localhost`. Para acceso compartido se debe publicar la aplicación en un dominio seguro con HTTPS, configurar el envío real de correo y preparar las cuentas del centro.

### Cuando contactes con soporte

Indica tu perfil, el mensaje exacto que aparece y la hora aproximada del intento. No envíes la contraseña, el enlace de activación ni el enlace de recuperación.

Guía preparada para el entorno local de Sistema FCT · 22 de octubre de 2026

Guía de acceso · Uso local de desarrollo · No se deben publicar datos personales ni credenciales
