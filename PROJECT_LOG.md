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

## Ultima sesion de trabajo (2026-04-04)

- Objetivo: cerrar errores 500 en Vercel, estabilizar runtime serverless Laravel y dejar endpoints productivos consumibles por mobile sin ngrok.

## Cambios realizados

- Se ejecutaron migraciones contra BD remota (`204.157.146.239:3308`) y se valido conectividad MySQL.
- Se corrigio bootstrap serverless para Vercel:
  - rutas de cache Laravel apuntadas a `/tmp`
  - directorio de vistas compiladas en runtime escribible.
- Se alineo ruteo API para Vercel reescrito:
  - prefijo dinamico en `RouteServiceProvider` para evitar doble `/api`.
- Se ajusto manejo de errores API:
  - respuestas JSON consistentes
  - `422` para validacion, `404` para not found, `500` para internos
  - `request_id` incluido en payload de error.
- Se ajustaron defaults serverless para evitar escritura en FS de solo lectura:
  - `CACHE_STORE` no-file en Vercel
  - `SESSION_DRIVER` no-file en Vercel.
- Se corrigio CORS para rutas efectivas (`v1/*`) ademas de `api/*`, resolviendo fallo CORS observado desde Flutter Web.
- Se verificaron endpoints productivos en Vercel:
  - `GET /` health `200`
  - `GET /api/v1/forms/active` `200`
  - `GET /api/v1/forms/{sid}/versions/{version}` `200`
  - `POST /api/v1/sync/responses/batch` con validaciones y flujo OK.

## Estado actual del proyecto

- API backend operativa en Vercel (`main`) sin dependencia de ngrok.
- Runtime Laravel estable bajo restricciones serverless (cache/session/rutas/CORS).
- Endpoint contract alineado con cliente mobile para formularios y sincronizacion.

## Trabajo en curso

- Monitoreo de logs productivos para detectar casos residuales de mapping/persistencia en payloads de campo reales.
- Ajuste fino de performance de sync batch en escenarios de alto volumen.

## Pendientes inmediatos

- Confirmar ciclo E2E en campo con APK final (captura offline -> sync -> verificacion en LimeSurvey).
- Mantener checklist operativo de Vercel (variables + deploy promocionado) para futuras iteraciones.
