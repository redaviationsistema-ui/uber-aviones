# Red Aviation Backend

## Objetivo
Esta capa actualiza el backend actual de marketplace hacia un modelo SaaS cerrado para Red Aviation.

## Principios
- Monetizacion por paquetes, membresias y suscripciones.
- Cliente y operador nunca comparten contacto directo.
- El matching se ejecuta dentro de Red Aviation.
- El sobrecargo participa solo con informacion operativa necesaria.
- Auditoria y anti-broker son piezas base del flujo.

## Roles
- `client`: cliente final.
- `provider`: operador/proveedor.
- `sobrecargo`: se almacena en `users.operational_role`.
- `admin`: control total.

## Componentes agregados
- Nuevas rutas en [routes/api_v1_red_aviation.php](/c:/VUELOS/backend%20vuelos/routes/api_v1_red_aviation.php)
- Nuevos servicios Red Aviation en `app/Servicios/RedAviation`
- Nuevos modelos operativos y de chat protegido en `app/Modelos`
- Nueva migracion SaaS en [database/migrations/2026_04_28_090000_agregar_capa_red_aviation_saas.php](/c:/VUELOS/backend%20vuelos/database/migrations/2026_04_28_090000_agregar_capa_red_aviation_saas.php)

## Flujo operativo
1. El cliente activa demo o suscripcion.
2. Crea una solicitud con control de limite por plan.
3. El matching genera invitaciones ciegas a operadores elegibles.
4. El operador acepta o rechaza dentro de la plataforma.
5. Red Aviation crea la operacion y mantiene el tracking.
6. El sobrecargo recibe briefing, checklist e incidencias.
7. El chat protegido filtra telefonos, correos, URLs y redes sociales.
8. Toda reincidencia genera bandera `anti_broker_flags`, auditoria y notificacion interna.

## Notas de compatibilidad
- No se elimino la capa previa de cotizaciones/reservas para no romper integraciones existentes.
- `sobrecargo` no reemplaza el campo `users.role`; se resuelve con `users.operational_role` para evitar una migracion destructiva.
- La transicion de comisiones a SaaS queda reflejada en configuracion y nuevos endpoints, pero la capa legacy sigue presente hasta su retiro definitivo.

## Stripe local
- El backend expone webhooks Stripe en `POST /api/v1/stripe/webhook` y `POST /api/v1/webhooks/stripe`.
- Para pruebas locales usa `php artisan serve` y luego `sh scripts/stripe-listen-local.sh`.
- El comando imprimira un secreto `whsec_...`; ese valor debe copiarse a `STRIPE_WEBHOOK_SECRET` en `.env`.
- Si quieres una prueba completa sin pasar por el frontend, usa `sh scripts/stripe-e2e-local.sh`.
- Ese script registra o reutiliza un cliente de prueba, crea una `flight_request`, le asigna monto de prueba y devuelve un `checkout_url` listo para abrir en Stripe Checkout.
- El mensaje `zsh: command not found: i` no pertenece a Stripe; normalmente indica que se tecleo una `i` suelta en la terminal despues del login.
