# Comunicación entre frontend y backend

Sistema FCT mantiene dos canales distintos. Las islas Vue progresivas y los
formularios HTML usan la sesión web y las acciones MVC; la API REST con tokens
se reserva para integraciones externas y el probador del panel **Integraciones**.
No se deben usar tokens Bearer desde las islas Vue.

## Canal de sesión MVC: vistas e islas Vue

Las peticiones pasan por `public/index.php` y las acciones declaradas en
`routes/web.php`, por ejemplo `index.php?action=disponibilidad`. El navegador
envía la cookie de sesión con `credentials: 'same-origin'`; las mutaciones
`POST`, `PUT`, `PATCH` y `DELETE` incluyen `X-CSRF-Token`. El layout publica el
token vigente en `meta[name="csrf-token"]`; cuando una isla ya dispone de un
token actualizado (por ejemplo, después de iniciar sesión), su cabecera explícita
tiene prioridad.

`frontend/src/api/client.ts` centraliza las peticiones de las islas:

- Añade `Accept: application/json` y, para cuerpos JSON, `Content-Type`.
- No fuerza `Content-Type` para `FormData`.
- Permite añadir parámetros de consulta mediante `params`.
- Incluye la cookie de sesión y el token CSRF para las mutaciones.
- Valida el sobre JSON y lanza un error si el backend responde con error o con
  un formato no reconocido.

El contrato de `JsonResponse` es:

```json
{ "success": true, "data": {} }
```

o:

```json
{ "success": false, "error": "Descripción del error" }
```

El tipo común es `ApiResponse<T>` de `frontend/src/types/api.ts`. En TypeScript,
`requestApi<T>(url, options)` devuelve directamente `data` después de validar
el sobre.

### Acciones MVC usadas por el cliente

| Isla o store | Acción | Método | CSRF |
|---|---|---:|---|
| `AvailabilitySchedule` | `disponibilidad` | POST | Sí |
| `ReviewOfferDecision` | `revisar-oferta` | POST | Sí |
| `GradeForm` | `guardar-calificacion` | POST | Sí |
| Store de perfil | `perfil-alumno` | POST | Sí |
| Store de asignaciones | `asignar-alumno` | POST | Sí |
| Store de revisión de convenios | `resolver-convenio` | POST | Sí |
| Store de sesión | `session` | GET | No |
| Store de sesión | `login`, `logout` | POST | Sí |
| `AssignedCompany` | `empresa-asignada` | GET | No |

La tabla refleja llamadas actuales a `requestApi`, no un catálogo de todas las
rutas MVC. `CreateOfferIsland`, por ejemplo, mejora los controles del formulario
HTML y no reemplaza su envío PHP. Si falla JavaScript, la navegación y los
formularios PHP siguen siendo el mecanismo de respaldo.

## Canal REST tokenizado: integraciones

`public/api.php?path=...` es un canal separado. El probador de la página MVC
`integraciones` llama el alias `/api/v1/...`, envía el token en
`Authorization: Bearer ...` y omite las cookies (`credentials: 'omit'`); no
utiliza la sesión MVC como autenticación de la API. Sus errores incluyen
`X-API-Error-Code`.

El probador valida en tiempo de ejecución el sobre discriminado antes de
presentar la respuesta como válida. Los consumidores TypeScript deben usar el
tipo `ApiResponse<T>` y comprobar sus dos variantes; el probador JavaScript
comparte la misma comprobación estructural para las respuestas que inspecciona.
Una respuesta HTTP exitosa sin un sobre válido no se considera una respuesta API
correcta.

La API también usa el sobre `JsonResponse`. En las listas, `data` contiene
`items` y `meta`, con los campos `page`, `per_page` y `total`. No se devuelve
`last_page`. Los tipos de paginación están en `frontend/src/types/api.ts`.
Consulta [API REST tokenizada](/api-rest) para recursos, permisos, límites y
ejemplos.

La API REST no se consume desde `requestApi`: ese helper es para el canal de
sesión MVC y no admite opciones Bearer. Mantener los canales separados evita
mezclar el token de integración con las credenciales de una sesión del navegador.
Actualmente, las búsquedas y escrituras REST se limitan al probador de
integraciones; no hay islas Vue que consuman `public/api.php`.

| Consumidor | URL REST | Métodos | Autenticación |
|---|---|---|---|
| Probador del panel `Integraciones` | `/api/v1/{recurso}` (o `/{recurso}/{id}`), enrutado a `public/api.php?path=...` | GET lista/detalle; POST creación; PATCH actualización/revisión | `Authorization: Bearer ...`; sin cookie (`credentials: 'omit'`) |

Los recursos disponibles para el probador son `alumnos`, `empresas`, `ofertas`,
`convenios` y `asignaciones`. En GET de lista se envían `page` y `per_page`;
los cuerpos de POST/PATCH son JSON. La matriz de permisos y los contratos de
cada recurso están en [API REST tokenizada](/api-rest).

## Verificación

```powershell
npm run typecheck
npm test
npm run build
npm run docs:build
```
