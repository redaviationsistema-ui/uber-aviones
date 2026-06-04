# Arquitectura de Base de Datos

## Objetivo

Esta base de datos ya soporta la operacion del negocio, pero su estructura venia mezclando campos historicos, campos de trabajo y algunas relaciones guardadas como texto. Este documento deja una lectura empresarial simple para que desarrollo, producto y operaciones entiendan donde vive cada dato.

## Principios actuales

1. `users` es la entidad principal de identidad.
2. `providers` representa la empresa operadora dueña del inventario operativo.
3. `aircraft` representa el inventario comercial y operativo del proveedor.
4. `flight_requests` representa la necesidad del cliente.
5. `request_matches`, `quotes`, `reservations`, `payments`, `commissions` y `payouts` representan la cadena comercial completa.
6. `airports` es el catalogo maestro de aeropuertos.

## Fuentes de verdad

- Identidad del usuario: `users`
- Propiedad del proveedor: `providers.user_id`
- Equipo asociado al proveedor: `users.provider_id`
- Catalogo de aeropuertos: `airports`
- Solicitud comercial: `flight_requests`
- Cotizacion formal: `quotes`
- Reserva formal: `reservations`
- Cobro al cliente: `payments`
- Liquidacion al proveedor: `payouts`

## Gobierno empresarial de proveedor y roles

La lectura recomendada del esquema es esta:

- `providers.user_id` define al propietario legal u operativo principal del proveedor
- `users.provider_id` define la pertenencia operativa del usuario a un proveedor
- `user_roles` define los roles reales del usuario
- `users.role` y `users.operational_role` quedan como columnas legacy sincronizadas para compatibilidad

Regla empresarial:

1. Si un usuario pertenece a un proveedor, debe tener rol `provider` en `user_roles`
2. Si un usuario es propietario de un proveedor, su `users.provider_id` debe apuntar a ese proveedor
3. El rol primario debe vivir en `user_roles.is_primary`
4. Las columnas legacy no deben ser la fuente de verdad para nuevas integraciones

## Regla de convivencia legacy + nuevo esquema

Para no romper compatibilidad, se conservaron campos heredados en texto como:

- `aircraft.base_airport`
- `profiles.base_airport`
- `flight_requests.origin`
- `flight_requests.destination`
- `flight_request_legs.origin`
- `flight_request_legs.destination`
- `reservation_legs.origin`
- `reservation_legs.destination`

Pero el esquema nuevo ya agrega referencias normalizadas:

- `aircraft.base_airport_id`
- `profiles.base_airport_id`
- `flight_requests.origin_airport_id`
- `flight_requests.destination_airport_id`
- `flight_request_legs.origin_airport_id`
- `flight_request_legs.destination_airport_id`
- `reservation_legs.origin_airport_id`
- `reservation_legs.destination_airport_id`

La regla recomendada es:

1. Validar contra `airports`
2. Guardar la relacion por `_id`
3. Mantener el texto solo como compatibilidad o snapshot

## Reglas empresariales importantes

### Usuarios y proveedores

- Un `provider` tiene un usuario propietario en `providers.user_id`
- Un `user` puede pertenecer operativamente a un proveedor mediante `users.provider_id`
- Los roles empresariales viven en `user_roles`, mientras `users.role` y `users.operational_role` quedan como compatibilidad

### Solicitudes y coincidencias

- Una solicitud puede producir varias coincidencias en `request_matches`
- Ya no deben existir coincidencias duplicadas para la misma combinacion:
  `flight_request_id + aircraft_id + provider_id`

### Aeropuertos

- `airports` debe ser el catalogo canonico
- Los codigos ICAO/IATA escritos a mano deben considerarse transitorios
- Para estabilidad entre motores, se conserva una forma canonica compatible con `icao`, `iata`, `icao_code`, `iata_code`, `created_at` y `updated_at`

### Estados y catalogos

- Los estados de dominio ya no deben inventarse libremente en controladores nuevos
- Los catalogos canonicos viven en:
  - `app/Enumeraciones/*`
  - `config/domain_catalogs.php`
- Para nuevas reglas de negocio, primero se agrega el estado al catalogo y despues se usa en servicios o validaciones

## Convencion de lectura recomendada

Cuando alguien del equipo quiera entender el flujo, debe leerlo asi:

1. `users`
2. `providers`
3. `aircraft`
4. `flight_requests`
5. `request_matches`
6. `quotes`
7. `reservations`
8. `payments`
9. `commissions`
10. `payouts`

## Beneficio del ajuste

Con este endurecimiento, la BD queda:

- mas entendible para equipos grandes
- mas segura contra datos incoherentes
- mas facil de mantener sin romper integraciones existentes
- mas lista para evolucionar a nivel empresarial

## Migraciones legacy

Las migraciones antiguas con deuda historica no deben borrarse ni editarse si ya fueron ejecutadas en entornos reales. La estrategia correcta es:

1. Congelarlas como historial
2. Aplicar migraciones nuevas de saneamiento
3. Mantener un esquema canonico documentado
4. Hacer limpieza mayor solo cuando se programe una consolidacion de historial
