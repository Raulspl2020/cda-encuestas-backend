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
