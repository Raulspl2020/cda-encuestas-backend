# Project Log

## Ultima sesion de trabajo (2026-04-01)

- Se analizo la compatibilidad del backend con Vercel: el proyecto (Laravel 10 + PHP 8.1) es desplegable en Vercel con runtime PHP, siempre que las bases MySQL remotas permitan conectividad desde Vercel.
- Se realizo la preparacion de Git para despliegue:
  - commit inicial y push a `origin/main` (`e75d451`)
  - configuracion para Vercel y push (`1460cda`)
- Se agrego soporte serverless para Laravel en Vercel:
  - entrypoint `api/index.php`
  - reglas de runtime/rutas en `vercel.json`
- Se acompano la configuracion en consola de Vercel (preset `Other`, variables de entorno Laravel/MySQL/LimeSurvey, correccion de `APP_URL` sin `=`).

## Cambios realizados

- Inicializacion y publicacion del repositorio remoto en GitHub.
- Configuracion de deployment para enrutar todas las peticiones a Laravel en Vercel.
- Guia operativa para carga de variables sensibles en Vercel (sin exponer secretos en el repo).

## Estado actual del proyecto

- Codigo fuente actualizado en `main` con configuracion de Vercel incluida.
- Ultimo deploy observado en Vercel fallo por usar commit anterior (`e75d451`) y flujo tipo Vite (`dist`).
- El commit correcto para redeploy es `1460cda`.

## Trabajo en curso

- Validar que Vercel tome el commit mas reciente y no el inicial.
- Verificar settings de build del proyecto en Vercel (`Framework Preset: Other`, sin `Output Directory`).

## Pendientes inmediatos

- Ejecutar redeploy desde `main` tomando commit `1460cda`.
- Confirmar variables de entorno minimas (`APP_KEY`, `APP_URL`, `DB_*`, `LS_DB_*`, `SESSION_DRIVER`, `CACHE_DRIVER`, `LOG_CHANNEL`).
- Probar endpoints API desplegados:
  - `GET /api/v1/forms/active`
  - `GET /api/v1/forms/{sid}/versions/{version}`
  - `POST /api/v1/sync/responses/batch`
- Si falla conexion a BD, ajustar firewall/whitelist/SSL del servidor MySQL remoto.
