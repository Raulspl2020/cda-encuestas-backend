# DEV History

## 2026-04-01 - Preparacion de deploy en Vercel (Laravel API)

### Descripcion tecnica del cambio

Se adapto el proyecto Laravel para ejecucion en Vercel con funciones PHP serverless. Se agrego un entrypoint en `api/index.php` que delega al front controller de Laravel (`public/index.php`) y se incorporo `vercel.json` para declarar runtime `vercel-php` y reglas de rutas hacia recursos publicos y API.

Adicionalmente, se completo la puesta en marcha del repositorio remoto (commit inicial + push) y se guio la configuracion operativa en Vercel para evitar el flujo de build de Vite en un backend PHP.

### Modulos afectados

- Infraestructura de despliegue serverless (Vercel)
- Bootstrap/entrada HTTP de Laravel en entorno Vercel
- Proceso de CI/CD basado en GitHub + Vercel

### Archivos modificados

- `api/index.php` (nuevo)
- `vercel.json` (nuevo)

### Impacto tecnico

- Habilita despliegue de la API Laravel en Vercel sin requerir servidor tradicional.
- Centraliza el ruteo HTTP hacia Laravel y preserva acceso a assets en `public`.
- Elimina dependencia del output `dist` de Vite para este backend.
- Requiere configuracion correcta de variables de entorno y conectividad a MySQL remoto (`DB_*` y `LS_DB_*`).
- Riesgo operativo pendiente: si Vercel despliega un commit anterior o mantiene preset incorrecto, el build falla antes de ejecutar PHP.

## 2026-04-04 - Estabilizacion productiva Vercel + CORS + contract API para mobile

### Descripcion tecnica del cambio

Se realizo hardening del backend Laravel en Vercel para corregir fallas de runtime observadas en produccion (`BindingResolutionException: view`, errores 500, CORS y filesystem read-only).

Cambios clave:
- Ajustes de bootstrap para Vercel serverless en `api/index.php` redirigiendo caches (`APP_*_CACHE`) y `VIEW_COMPILED_PATH` a `/tmp`.
- Ajuste de prefijo de rutas API en `RouteServiceProvider` para compatibilidad con rewrite externo `/api/v1/*` -> ruta interna efectiva.
- Refuerzo de manejo de excepciones API en `Handler.php` con respuestas JSON consistentes por tipo de error (`422/404/500`) y `request_id`.
- Configuracion de drivers serverless-safe en Vercel para cache/sesion (evitando persistencia en disco de solo lectura).
- Correccion de CORS en `config/cors.php` agregando `v1/*` (ademas de `api/*`) para preflight y requests reales desde Flutter Web.
- Verificacion de endpoints productivos (`forms`, `versions`, `sync batch`) y validacion de migraciones sobre BD remota.

### Modulos afectados

- Bootstrap/entrada serverless (`api/index.php`)
- Routing API (`app/Providers/RouteServiceProvider.php`)
- Exception handling HTTP/API (`app/Exceptions/Handler.php`)
- Configuracion runtime (`config/cache.php`, `config/session.php`, `config/cors.php`)
- Endpoints raiz y API (`routes/web.php`, `routes/api.php`)

### Archivos modificados

- `api/index.php`
- `app/Exceptions/Handler.php`
- `app/Providers/RouteServiceProvider.php`
- `config/cache.php`
- `config/session.php`
- `config/cors.php`
- `routes/web.php`

### Impacto tecnico

- El backend queda operativo en Vercel sin ngrok, con compatibilidad estable para cliente mobile/web.
- Se eliminan errores 500 asociados a provider/view y a escritura en paths no permitidos.
- CORS queda funcional para endpoints `api/v1` consumidos desde Flutter Web.
- Se mejora trazabilidad de errores mediante payload JSON uniforme y `request_id`.

## 2026-04-21 - Soporte file_upload, resiliencia de sync y recuperacion de contrato API mobile

### Descripcion tecnica del cambio

- Se incorporo soporte estructural para preguntas LimeSurvey tipo `|` (`file_upload`) en backend puente.
- Se agrego endpoint multipart para carga previa de archivos (`POST /api/v1/uploads/survey-file`) con devolucion de `file_token` y metadata.
- Se implemento pipeline de resolucion de archivos en sincronizacion:
  - resolucion por `file_token`,
  - copia al arbol de uploads de LimeSurvey,
  - construccion del JSON de respuesta compatible con `Response::getFiles()` de LimeSurvey.
- Se agrego tabla auxiliar `survey_upload_files` para trazabilidad del archivo entre upload previo y sync final.
- Se corrigio validacion de payload en sync para no descartar `answers.*.value`, evitando inserciones vacias.
- Se reforzo serializacion de formularios fallback:
  - mapeo explicito de `raw_type '|'` a `file_upload`,
  - exposicion de `raw_type` y `supports_file_upload` para el cliente,
  - normalizacion de `attributes` vacio en contrato API.
- Se aplicaron ajustes de robustez en cache/version de formularios para reducir desalineaciones entre payload cacheado y estructura live.

### Modulos afectados

- API routing (`routes/api.php`)
- Controladores HTTP (`FormsController`, nuevo `SurveyUploadController`, `SyncController`)
- Servicios de dominio (`SyncService`, nuevo `SurveyFileUploadService`, `FormsService`, `LimeSurveyAdapter`)
- Persistencia Eloquent (nuevo modelo `SurveyUploadFile`)
- Migraciones (`survey_upload_files`)
- Operacion CLI (`LimeSurveyRebuildMapCommand`)

### Archivos modificados

- `app/Http/Controllers/SyncController.php`
- `app/Http/Controllers/FormsController.php`
- `app/Http/Controllers/SurveyUploadController.php` (nuevo)
- `app/Services/SyncService.php`
- `app/Services/FormsService.php`
- `app/Services/SurveyFileUploadService.php` (nuevo)
- `app/Adapters/LimeSurveyAdapter.php`
- `app/Models/SurveyUploadFile.php` (nuevo)
- `database/migrations/2026_04_21_000007_create_survey_upload_files_table.php` (nuevo)
- `app/Console/Commands/LimeSurveyRebuildMapCommand.php`
- `routes/api.php`

### Impacto tecnico

- Habilita contrato backend para encuestas con preguntas de archivo sin romper descarga de formulario en mobile.
- Reduce riesgo de perdida silenciosa de datos al preservar `answers.*.value` durante validacion HTTP.
- Introduce trazabilidad y control de estado del archivo entre upload previo y confirmacion final de sync.
- Mantiene compatibilidad con estrategia de idempotencia por `interview_uuid`.
- Riesgo operativo abierto: en runtime serverless (Vercel) la escritura hacia filesystem LimeSurvey requiere configuracion de infraestructura/almacenamiento compatible para cerrar E2E de adjuntos.
