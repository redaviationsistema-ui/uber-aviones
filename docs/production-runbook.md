# Runbook de producción Red Sky

## Criterio de liberación

No desplegar mientras falle alguna condición: suite Laravel/Vue/Flutter, migración y rollback en PostgreSQL equivalente a producción, `/health`, configuración SMTP/S3/Stripe, Firebase/APNs, firma Android/iOS o respaldo restaurable. Nunca guardar secretos en Git.

## Preflight

1. Crear respaldo lógico PostgreSQL cifrado y anotar checksum, tamaño y fecha. Validar restauración en una instancia aislada. Objetivo inicial: RPO 24 h y RTO 4 h; ajustar con negocio antes de producción.
2. Confirmar `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, CORS permitido, `LOG_CHANNEL`, SMTP real, S3 privado, Stripe live y secreto de webhook. Ejecutar `php artisan config:cache` sólo después de validar variables.
3. Probar en staging: `php artisan migrate:status`, `php artisan test`, `composer audit`, `/health`, subida/descarga S3, correo real controlado y eventos Stripe firmados.
4. Confirmar que scheduler y worker supervisado están activos. El cron debe ejecutar `php artisan schedule:run` cada minuto. El worker debe usar reintentos limitados, timeout y reinicio posterior al deploy.
5. Confirmar artefactos Vue y Flutter generados con sus variables de staging/producción, Firebase/APNs y firma de distribución.

## Despliegue Laravel

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan schedule:list
```

Después, verificar `/health` (HTTP 200), login, alta/consulta de solicitud, checkout de prueba autorizado, webhook, almacenamiento y correo. No usar `migrate:fresh`, seeders ni `APP_DEBUG=true` en producción.

## Despliegue Vue

```sh
npm ci
npm test
npm run build
```

Publicar `dist/` de forma atómica y comprobar rutas directas del SPA, CSP/CORS, login, paneles por rol y retorno de Stripe. Conservar el artefacto anterior para rollback.

## Despliegue móvil

Generar con `APP_ENV=production`, `API_BASE_URL` HTTPS y `APP_VERSION`. Android requiere keystore de release y `google-services.json`; iOS requiere perfil/certificado, `GoogleService-Info.plist` y APNs. Probar instalación limpia, actualización, login/logout/cambio de cuenta, push foreground/background/cerrada y enlaces universales en dispositivos físicos.

## Rollback

1. Detener nueva entrada de tráfico o activar mantenimiento si hay riesgo de escrituras incompatibles.
2. Reponer el artefacto backend/frontend anterior y ejecutar `php artisan queue:restart`.
3. Revertir una migración sólo si su `down()` fue ensayado con una copia de producción y el código anterior requiere el esquema previo: `php artisan migrate:rollback --step=1 --force`.
4. Si una migración transformó o eliminó datos, restaurar el respaldo validado en una instancia nueva y cambiar el tráfico; no improvisar rollback destructivo sobre producción.
5. Verificar `/health`, métricas y flujos críticos; documentar tiempos y pérdida de datos real.

## Observabilidad y alertas

- Alertar por `/health` no-200, tasa 5xx, latencia p95/p99, errores de autenticación, fallos/edad de cola, jobs fallidos y ausencia de ejecución del scheduler.
- Alertar por errores y latencia Stripe/webhooks, pagos pendientes anómalos, fallos S3/SMTP y entregas push rechazadas.
- Centralizar logs con correlación de solicitud, usuario y entidad, sin tokens, contraseñas, secretos, biometría ni payloads completos de pago/push.
- Revisar diariamente `queue:failed`; definir dashboards y responsables de guardia. Ensayar restauración y rollback periódicamente.

## Bloqueos que requieren infraestructura externa

La certificación final exige credenciales/instancias de PostgreSQL staging, S3, SMTP, Stripe, Firebase/APNs, dominios para universal/app links y cuentas de firma/distribución. La ausencia de cualquiera mantiene el veredicto en **NO LISTO**, aunque las pruebas locales pasen.
