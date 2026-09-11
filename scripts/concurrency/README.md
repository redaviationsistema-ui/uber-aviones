# Fase 1: concurrencia real de reservas y DocuSign

Ejecutar desde la raíz del backend. Requiere PostgreSQL local y una base vacía exclusiva con nombre `uberaviones_phase1_concurrency_test_<número>`. Nunca usar la configuración normal de `.env` para migrar o probar.

```sh
export APP_ENV=testing DB_URL= DB_CONNECTION=pgsql DB_HOST=/tmp DB_PORT=5432
export DB_DATABASE=uberaviones_phase1_concurrency_test_20260911 DB_USERNAME=redaviation DB_PASSWORD=
export DB_CONNECT_TIMEOUT=0 DB_PERSISTENT=false LOG_CHANNEL=stderr CACHE_STORE=array SESSION_DRIVER=array
# Solo sobre una base NUEVA y vacía creada expresamente para testing:
php artisan migrate --env=testing
php scripts/concurrency/reservation_concurrency_test.php default
php scripts/concurrency/reservation_concurrency_test.php different-keys
php scripts/concurrency/docusign_concurrency_test.php default
php scripts/concurrency/docusign_concurrency_test.php missing-contract
php scripts/concurrency/docusign_concurrency_test.php regenerate
```

Adaptar usuario/host a la instalación local. `bootstrap.php` rechaza producción, hosts no locales, DB_URL y nombres de base ajenos al patrón. Los fixtures se conservan únicamente en esa base de pruebas; cada ejecución usa entidades nuevas. No borrar ni migrar bases existentes con datos reales.

`run_concurrency.php` mantiene un bloqueo de fila desde una conexión coordinadora y lanza dos procesos PHP con `proc_open`. Cada worker usa su propio intérprete/conexión, token y kernel HTTP real (incluye rutas y middleware). La coordinadora exige ver ambos PID en `pg_stat_activity` con `wait_event_type=Lock` antes de liberar la fila. Hay deadline de 12 segundos y statement_timeout de 20 segundos; las excepciones salen con código 1.

Se verifican estados HTTP 200/201, ID canónico compartido, retry posterior, una reserva, un envelope y conteos antes/después de TODAS las tablas públicas. Solo se permiten los registros relacionados esperados; pagos, operaciones, notificaciones y eventos de dominio deben permanecer sin nuevos registros/eventos. Dos claves de idempotencia diferentes generan dos registros técnicos, pero una sola reserva/contrato/comisión/hold/auditoría.

DocuSign y PDF son fakes. El contador compartido de `crearEnvelopeParaFirmaEmbebida()` usa `flock`: debe ser exactamente 1 incluso después del retry. No se llama a DocuSign real. El escenario `missing-contract` también comprueba la creación concurrente del contrato, y `regenerate` parte de un envelope expired.

Los antiguos `*_store_worker.php` no son utilizados por estos entrypoints. El worker vigente es `http_worker.php`.

Resultados de cierre y auditoría completa: frontend `docs/audits/cliente-fase1-bloques-9-10-12-2026-09-11.md`, con evidencia JSON en el subdirectorio del mismo nombre sin extensión.
