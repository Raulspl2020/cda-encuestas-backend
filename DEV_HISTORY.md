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
