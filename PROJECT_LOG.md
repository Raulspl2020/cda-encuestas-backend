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

## Ultima sesion de trabajo (2026-04-21)

- Objetivo: recuperar consistencia de sincronizacion en produccion, habilitar soporte de preguntas tipo archivo (`|`) y estabilizar contrato API para mobile.

## Cambios realizados

- Se desplegaron cambios en `main` para hardening de sync y nuevos flujos de upload:
  - commits relevantes: `07e1249`, `3c1b8f0`.
- Se agrego endpoint `POST /api/v1/uploads/survey-file` para carga previa de archivos de encuesta.
- Se implemento servicio dedicado para manejo de archivos de encuestas:
  - almacenamiento temporal,
  - tokenizacion (`file_token`),
  - conversion a formato JSON esperado por LimeSurvey para preguntas `|`.
- Se extendio `SyncService` para resolver respuestas `file_upload` y transformar `file_token` a payload final LimeSurvey.
- Se agrego persistencia de uploads en tabla auxiliar (`survey_upload_files`) y se ejecuto migracion.
- Se corrigio validacion de sync batch para preservar `answers.*.value`/`response`/`subquestion_code`.
- Se reforzo serializacion de formularios para cliente mobile:
  - mapeo explicito de `| -> file_upload`,
  - inclusion de `raw_type` y `supports_file_upload`.
- Se valido en produccion:
  - `GET /api/v1/forms/833381/versions/20260417201508` responde `200`,
  - `POST /api/v1/sync/responses/batch` persiste valores en tabla LS,
  - `POST /api/v1/uploads/survey-file` expone endpoint pero requiere ajuste de entorno para evitar `500` en runtime serverless.

## Estado actual del proyecto

- Backend en Vercel actualizado con fixes de sync y soporte base para `file_upload`.
- Contrato de formulario para SID `833381` ya expone tipo `file_upload` para `G02Q14`.
- Flujo de sync textual/opciones estable y persistiendo datos en LimeSurvey.
- Flujo de upload de archivos implementado a nivel de codigo, pendiente cierre de configuracion de infraestructura productiva (`LS_UPLOAD_DIR` / acceso FS compatible).

## Trabajo en curso

- Ajuste final de despliegue para normalizar `attributes` vacio como objeto en todas las respuestas API productivas.
- Diagnostico operativo de `500` en `/uploads/survey-file` bajo Vercel serverless (storage y permisos/ruta de archivos LS).
- Validacion E2E completa de file upload (adjuntar -> sync -> visualizacion en LimeSurvey).

## Pendientes inmediatos

- Forzar redeploy en Vercel del ultimo commit productivo y confirmar version activa por fecha/hash.
- Confirmar variables de entorno y estrategia de storage para archivos:
  - `LS_UPLOAD_DIR`
  - `SURVEY_UPLOAD_DISK`
- Ejecutar prueba controlada de `POST /api/v1/uploads/survey-file` con archivo real y revisar logs de error.
- Validar en LimeSurvey que los adjuntos de `G02Q14` queden visibles/descargables desde respuestas.
