# Docs

## Alcance

Este documento describe primero las funciones orientadas a cliente dentro del backend. El objetivo es que otra persona pueda entender rapidamente que hace cada funcion relevante del flujo cliente, que valida, que modifica y que devuelve.

Se cubren dos bloques principales:

1. API cliente tradicional definida en `routes/api_v1_cliente.php`.
2. API cliente Red Aviation definida en `routes/api_v1_red_aviation.php`.

## Mapa General

### Rutas cliente tradicionales

- `routes/api_v1_cliente.php`
- Controladores principales:
  - `AutenticacionControlador`
  - `BiometricControlador`
  - `SuscripcionControlador`
  - `DemoControlador`
  - `AeronaveControlador`
  - `SolicitudVueloControlador`
  - `CotizacionControlador`
  - `ReservaControlador`
  - `PagoControlador`
  - `StripePagoControlador`
  - `MetodoPagoControlador`
  - `NotificacionControlador`
  - `RedAviation\FlightMembershipControlador`

### Rutas cliente Red Aviation

- `routes/api_v1_red_aviation.php`
- Controlador principal:
  - `RedAviation\ClienteControlador`

## 1. Cliente Tradicional

### `AutenticacionControlador`

#### `me(Request $request)`

- Ruta principal: `GET /cliente/perfil`
- Carga al usuario autenticado con sus relaciones de perfil y contexto de acceso.
- Devuelve tres bloques clave:
  - `user`: version serializada del usuario.
  - `access`: estado comercial o de acceso del usuario.
  - `login_context`: informacion util para frontend sobre el contexto de sesion.
- Su funcion es ser el endpoint base para poblar el perfil del cliente despues del login.

#### `updatePerfil(Request $request)`

- Ruta principal: `PUT /cliente/perfil`
- Valida datos editables de perfil y perfil comercial: nombre, telefono, empresa, giro, direccion, avatar y datos fiscales.
- Actualiza campos directos del usuario y crea o actualiza el registro asociado en `profile`.
- Si llega `avatar_url` y no `avatar`, reutiliza ese valor para mantener compatibilidad.
- Devuelve el usuario ya refrescado con `profile`, `provider` y `ownedProvider`.

### `BiometricControlador`

#### `detectFace(Request $request)`

- Ruta principal: `POST /cliente/biometric/detect-face`
- Recibe una selfie, la guarda en almacenamiento privado y la manda a AWS Rekognition.
- Casos principales:
  - si no puede leer el archivo responde error 422;
  - si AWS falla responde 502;
  - si no detecta rostro o detecta varios, rechaza la validacion y registra el intento;
  - si detecta un solo rostro calcula confianza, brillo, nitidez, pose y oclusion.
- Tambien sincroniza el estado biometrico del usuario para dejar evidencia del resultado.
- Es la pieza de verificacion biometrica previa a procesos de identidad o seguridad.

### `SuscripcionControlador`

#### `status(Request $request)`

- Rutas principales:
  - `GET /cliente/dashboard`
  - `GET /cliente/demo/estado`
  - `GET /cliente/suscripcion/estado`
- No calcula un dashboard complejo.
- Solo devuelve `accessStatus()` del usuario autenticado.
- En la practica el frontend usa este endpoint para saber si el cliente tiene demo, suscripcion o acceso activo.

#### `subscribe(Request $request)`

- Ruta principal: `POST /cliente/suscripcion/contratar`
- Valida el `plan_id` y datos de referencia del pago.
- Busca el plan, calcula fecha de expiracion segun ciclo mensual o anual y cancela cualquier suscripcion activa previa del usuario.
- Crea una nueva suscripcion marcada como `active` y un pago marcado como `paid`.
- Devuelve la suscripcion con su plan ya cargado.
- En este flujo el backend asume un alta directa y registra el cobro como pagado.

#### `cancel(Request $request)`

- Ruta principal: `POST /cliente/suscripcion/cancelar`
- Marca como canceladas las suscripciones activas del usuario.
- Devuelve solo un mensaje de confirmacion.

### `DemoControlador`

#### `activate(Request $request)`

- Ruta principal: `POST /cliente/demo/activar`
- Permite activar una demo gratuita una sola vez por usuario.
- Si el usuario ya tuvo demo, responde `409`.
- Crea una demo activa por 15 dias y escribe auditoria.
- Devuelve la demo creada y el nuevo `accessStatus()` del usuario.

### `AeronaveControlador`

#### `search(Request $request)`

- Ruta principal: `POST /cliente/buscar-vuelo`
- Es el buscador clasico de aeronaves para cliente.
- Filtra aeronaves segun criterios enviados por el cliente y devuelve opciones disponibles.
- Se usa como flujo previo a solicitud o cotizacion cuando el cliente todavia no esta dentro del flujo Red Aviation.

### `SolicitudVueloControlador`

#### `index(Request $request)`

- Ruta principal: `GET /cliente/solicitudes`
- Lista solicitudes de vuelo con `matches` y `quotes`.
- Si el usuario es cliente y no admin, limita la consulta a sus propias solicitudes.
- Devuelve paginacion simple.

#### `store(Request $request)`

- Ruta principal: `POST /cliente/solicitudes`
- Valida origen, destino, fecha/hora de salida, regreso opcional, pasajeros y tipo de viaje.
- Normaliza `departure_datetime` y `return_datetime` cuando llegan como fecha y hora separadas.
- Intenta resolver los aeropuertos de origen y destino.
- Crea la solicitud con estado `pending`.
- Despues ejecuta matching basico de aeronaves, escribe auditoria y dispara notificaciones a proveedores.
- Devuelve la solicitud ya cargada con matches y proveedor de cada aeronave.

#### `show(Request $request, SolicitudVuelo $flightRequest)`

- Ruta principal: `GET /cliente/solicitudes/{flightRequest}`
- Verifica que el cliente solo vea su propia solicitud.
- Devuelve la solicitud con `matches` y `quotes`.

#### `history(Request $request)`

- Ruta principal: `GET /cliente/historial`
- Devuelve un consolidado historico para el cliente:
  - solicitudes de vuelo;
  - reservas;
  - pagos asociados a reservas.
- Limita cada bloque a los ultimos 50 registros.
- Es util para construir una vista unificada de actividad del cliente.

### `CotizacionControlador`

#### `index(Request $request)`

- Ruta principal: `GET /cliente/cotizaciones`
- Lista cotizaciones cargando solicitud, aeronave y proveedor.
- Si el usuario es cliente no admin, restringe a las cotizaciones de sus solicitudes.
- Devuelve paginacion de 20 elementos.

#### `show(Request $request, Cotizacion $quote)`

- Ruta principal: `GET /cliente/cotizaciones/{quote}`
- Aplica control de acceso por cliente o proveedor.
- Carga solicitud, aeronave, proveedor e items de la cotizacion.
- Adicionalmente incluye una previsualizacion de beneficios de `flight_membership`.
- Es el endpoint de detalle completo de una cotizacion.

#### `accept(Request $request, Cotizacion $quote)`

- Ruta principal: `POST /cliente/cotizaciones/{quote}/aceptar`
- Verifica que la cotizacion sea del cliente y que su estado actual sea `sent`.
- Cambia el estado a `accepted`.
- Escribe auditoria de aceptacion.
- Devuelve la cotizacion actualizada.

#### `reject(Request $request, Cotizacion $quote)`

- Ruta principal: `POST /cliente/cotizaciones/{quote}/rechazar`
- Verifica propiedad de la cotizacion.
- Cambia el estado a `rejected`.
- Devuelve la cotizacion actualizada.

#### `createAircraftHold(CreateAircraftHoldRequest $request, Cotizacion $quote)`

- Ruta principal: `POST /cliente/cotizaciones/{quote}/aircraft-hold`
- Crea un bloqueo temporal de aeronave para la cotizacion seleccionada.
- Valida que el `quote_id` del cuerpo coincida con el de la URL.
- Carga la solicitud, la aeronave, sus documentos y el contexto de ruta.
- Usa servicios de ruta, elegibilidad y disponibilidad para asegurar que el hold es valido antes de reservar inventario.
- Este endpoint es clave para evitar que la aeronave se pierda mientras el cliente avanza a contrato o pago.

#### `showAircraftHold(Request $request, Cotizacion $quote)`

- Ruta principal: `GET /cliente/cotizaciones/{quote}/aircraft-hold`
- Consulta el hold activo asociado a una cotizacion.
- Sirve para que frontend sepa si el inventario sigue apartado o ya expiro.

#### `releaseAircraftHold(Request $request, Cotizacion $quote)`

- Ruta principal: `DELETE /cliente/cotizaciones/{quote}/aircraft-hold`
- Libera manualmente el hold de aeronave.
- Se usa cuando el cliente abandona el flujo o cambia de opcion.

### `ReservaControlador`

#### `index(Request $request)`

- Ruta principal: `GET /cliente/reservas`
- Lista reservas con relaciones de cotizacion, aeronave, proveedor, solicitud y pagos.
- Si el usuario es cliente no admin, solo muestra sus reservas.
- Antes de responder normaliza estados pendientes de Stripe para evitar inconsistencias visuales.

#### `show(Request $request, mixed $reservation)`

- Ruta principal: `GET /cliente/reservas/{reservation}`
- Resuelve la reserva por ID de reserva o por `flight_request_id`.
- Valida acceso por cliente o proveedor.
- Devuelve detalle completo: quote, aircraft, provider, legs, contract, review y payments.

#### `paymentAvailability(Request $request, mixed $reservation)`

- Rutas principales:
  - `GET /cliente/reservas/{reservation}/payment-availability`
  - `GET /cliente/solicitudes/{reservation}/payment-availability`
- Evalua si la reserva esta en condiciones de pagarse.
- Usa `AircraftAvailabilityService` para validar hold, inventario y otros bloqueos.
- Devuelve una bandera `can_pay`, contexto tecnico y un mensaje legible para frontend.

#### `paymentAuthorization(Request $request, mixed $reservation)`

- Rutas principales:
  - `GET /cliente/reservas/{reservation}/payment-authorization`
  - `GET /cliente/reservas/{reservation}/autorizacion-pago`
- Ejecuta una evaluacion mas estricta del flujo de cobro.
- Usa `ReservationPaymentAuthorizationService`.
- Devuelve `200` si el pago esta autorizado y `409` si hay razones bloqueantes.

#### `operation(Request $request, mixed $reservation)`

- Ruta principal: `GET /cliente/reservas/{reservation}/operacion`
- Busca la operacion mas reciente asociada a la solicitud de vuelo de la reserva.
- Devuelve informacion operativa disponible para el cliente, incluyendo timeline si existe.

#### `store(Request $request)`

- Ruta principal: `POST /cliente/reservas`
- Crea una reserva a partir de `quote_id` o `flight_request_id`.
- Usa `IdempotencyService` para impedir duplicados por reintentos del frontend.
- Reglas principales:
  - si viene `quote_id`, la cotizacion debe estar aceptada;
  - si viene `flight_request_id`, la solicitud debe pertenecer al cliente y cumplir requisitos de reserva.
- Crea o reutiliza una reserva y mueve la solicitud al estado correspondiente.

#### `showContract(Request $request, mixed $reservation, DocuSignServicio $docuSignServicio)`

- Rutas principales:
  - `GET /cliente/reservas/{reservation}/contrato`
  - `GET /cliente/solicitudes/{reservation}/contrato`
  - `GET /client/flight-requests/{reservation}/contract`
  - `GET /client/reservations/{reservation}/contract`
- Construye o recupera el contrato asociado a la reserva.
- Devuelve el contrato, la reserva y una bandera que indica si DocuSign esta configurado.

#### `downloadContractPdf(Request $request, mixed $reservation)`

- Rutas principales:
  - `GET /cliente/reservas/{reservation}/contrato/pdf`
  - `GET /cliente/solicitudes/{reservation}/contrato/pdf`
- Genera el PDF del contrato usando la vista `pdf.contract` y lo descarga.

#### `generateContract(Request $request, mixed $reservation)`

- Rutas principales:
  - `POST /cliente/reservas/{reservation}/contrato/generar`
  - `POST /cliente/solicitudes/{reservation}/contrato/generar`
- Regenera el contrato usando snapshot comercial.
- Tambien usa idempotencia para evitar recreaciones repetidas por doble click o reintentos.
- Escribe auditoria de regeneracion.

#### `showContractStatusById(...)`

- Ruta principal: `GET /cliente/contratos/{contract}/estado`
- Reconcila el estado local del contrato con DocuSign.
- Si encuentra un sobre completado descarga el PDF firmado y actualiza el contrato.
- Devuelve:
  - contrato;
  - reserva;
  - orden de pago mas reciente;
  - estado normalizado para frontend;
  - siguiente accion sugerida.
- Es el endpoint de seguimiento principal del flujo de firma.

#### `downloadSignedContractPdf(Request $request, ContratoReserva $contract)`

- Ruta principal: `GET /cliente/contratos/{contract}/pdf-firmado`
- Permite descargar el PDF firmado ya reconciliado y almacenado.
- Rechaza la operacion si el contrato aun no tiene PDF firmado.

#### `startEmbeddedSigning(...)`

- Rutas principales:
  - `POST /cliente/reservas/{reservation}/contrato/docusign`
  - `POST /cliente/solicitudes/{reservation}/contrato/docusign`
- Prepara el contrato para firma embebida en DocuSign.
- Reglas importantes:
  - prohibe que el cliente mande HTML de contrato arbitrario;
  - valida que DocuSign este configurado;
  - puede regenerar el contrato si se solicita;
  - genera o reutiliza PDF local;
  - crea envelope y recipient view;
  - guarda `docusign_envelope_id`, estado y ruta del PDF.
- Devuelve URL de firma embebida para frontend.

#### `signContract(...)`

- Rutas principales:
  - `POST /cliente/reservas/{reservation}/contrato/firmar`
  - `POST /cliente/solicitudes/{reservation}/contrato/firmar`
- Esta funcion no firma directamente.
- Siempre responde error `422` para forzar que la firma valida solo llegue desde DocuSign cuando el envelope quede `completed`.
- Es una barrera de seguridad para no permitir al cliente simular una firma.

#### `cancel(Request $request, mixed $reservation)`

- Ruta principal: `POST /cliente/reservas/{reservation}/cancel`
- Cancela una reserva si el vuelo no ha iniciado y si el workflow no esta ya en tracking o completado.
- Usa `ReservationLifecycleService` para ejecutar la cancelacion completa.
- Devuelve la reserva actualizada y su solicitud relacionada.

#### `rateService(Request $request, mixed $reservation)`

- Ruta principal: `POST /cliente/reservas/{reservation}/calificar`
- Solo permite calificar reservas `completed`.
- Crea o actualiza una `CalificacionServicio`.
- Guarda puntuacion, comentario y fecha de envio.
- Escribe auditoria de la calificacion.

### `PagoControlador`

#### `index(Request $request)`

- Ruta principal: `GET /cliente/pagos`
- Lista pagos del cliente con su reserva y contrato.
- Devuelve paginacion de 20 elementos.

#### `storeReservaPago(Request $request, mixed $reservation)`

- Ruta principal: `POST /cliente/reservas/{reservation}/pagar`
- Registra una orden de pago manual para la reserva.
- Reglas principales:
  - la reserva debe pertenecer al cliente;
  - primero debe existir contrato firmado;
  - no debe estar ya confirmada o pagada.
- Si existe un pago pendiente o fallido lo reutiliza y actualiza.
- Si no existe, crea uno nuevo.
- Cambia la reserva a `pending_payment` y guarda auditoria.
- Ignora cualquier intento del cliente de forzar estados terminales en el payload.

#### `retryReservaPago(Request $request, mixed $reservation)`

- Ruta principal: `POST /cliente/reservas/{reservation}/reintentar-pago`
- Reinicia el ultimo pago de la reserva a estado `pending`.
- Limpia `failure_reason`, `gateway_response` y `paid_at`.
- Regresa la reserva a `pending_payment` y libera bloqueos de disponibilidad vinculados al intento anterior.

### `StripePagoControlador`

#### `confirmFlightRequestPayment(Request $request)`

- Rutas principales:
  - `POST /cliente/stripe/payment-intent/confirm`
  - `POST /stripe/payment-intent/confirm`
- Actualmente esta blindado.
- Siempre responde `422` con el codigo `CLIENT_PAYMENT_CONFIRMATION_FORBIDDEN`.
- La intencion es que el cliente no pueda marcar pagos como confirmados manualmente; solo un webhook firmado de Stripe puede hacerlo.

#### `confirmReservationPayment(Request $request, mixed $reservation)`

- Ruta principal: `POST /cliente/reservas/{reservation}/pago/confirmar`
- Igual que la funcion anterior, esta bloqueada deliberadamente.
- Devuelve `422` para impedir confirmaciones client-side de pagos.

#### `createCheckout(Request $request)`

- Rutas principales:
  - `POST /cliente/stripe/checkout/create`
  - `POST /stripe/checkout/create`
- Crea o reutiliza una sesion de Stripe Checkout para pagar una reserva.
- Valida:
  - configuracion de Stripe;
  - propiedad de la solicitud;
  - monto valido;
  - que no este ya pagada;
  - snapshot comercial consistente;
  - autorizacion para pago;
  - hold valido de aeronave.
- Si encuentra una sesion pendiente reutilizable la devuelve para evitar duplicados.
- Si no, crea la sesion en Stripe, persiste la orden de pago y deja la reserva en `pending_payment`.

#### `createPaymentIntent(Request $request)`

- Ruta principal: `POST /cliente/stripe/payment-intent`
- Similar a `createCheckout`, pero crea un `PaymentIntent`.
- Revisa propiedad, monto, pagos previos, hold y estados pendientes.
- Si encuentra un checkout reutilizable pendiente puede responder con esa sesion.
- Si no, crea el `PaymentIntent`, actualiza solicitud y reserva, y guarda un `Pago` pendiente.

#### `createWireIntent(Request $request)`

- Ruta principal: `POST /cliente/stripe/wire-intent`
- No crea un cargo Stripe directo.
- Genera una referencia de transferencia bancaria y registra un pago pendiente.
- Cambia la solicitud a `pending_bank_confirmation`.
- Devuelve instrucciones bancarias configuradas en `services.stripe`.

#### `success(Request $request)`

- Ruta principal: `GET /cliente/stripe/checkout/success`
- Reconcila el retorno exitoso de Stripe Checkout.
- Busca pago, reserva o solicitud usando `session_id`, `reservation_id` o `flight_request_id`.
- Sincroniza la sesion real de Stripe, intenta finalizar pagos pendientes y devuelve el estado consolidado para frontend:
  - `payment_status`;
  - `booking_status`;
  - `checkout_url`;
  - `checkout_reusable`;
  - `requires_new_checkout`.
- Es el endpoint de consulta post-pago del frontend.

#### `cancel(Request $request)`

- Ruta principal: `GET /cliente/stripe/checkout/cancel`
- Marca como cancelado el flujo de Checkout cuando el cliente abandona el pago.
- Busca el pago pendiente por sesion, actualiza estado y deja traza de auditoria.

#### `mobileReturn(Request $request)`

- Ruta principal: `GET /client/flight-payment/mobile-return`
- Atiende el retorno de pago en aplicaciones moviles.
- Si el flujo fue exitoso intenta reconciliar la sesion antes de redirigir.
- Luego hace redirect a un deep link `redsky://cliente/pago?...`.

### `MetodoPagoControlador`

#### `index(Request $request)`

- Ruta principal: `GET /cliente/metodos-pago`
- Devuelve los metodos de pago del usuario ordenados por fecha.

#### `store(Request $request)`

- Ruta principal: `POST /cliente/metodos-pago`
- Guarda un metodo de pago del usuario.
- Si llega `is_default = true`, primero desmarca los anteriores.
- Crea el registro y devuelve el metodo persistido.

#### `destroy(Request $request, MetodoPago $paymentMethod)`

- Ruta principal: `DELETE /cliente/metodos-pago/{paymentMethod}`
- Solo permite borrar metodos pertenecientes al usuario autenticado.

### `NotificacionControlador`

#### `index(Request $request)`

- Rutas principales:
  - `GET /cliente/notificaciones`
  - `GET /notifications`
- Lista notificaciones paginadas del usuario y devuelve tambien el contador de no leidas.

#### `unreadCount(Request $request)`

- Ruta principal: `GET /notifications/unread-count`
- Devuelve solo el numero de notificaciones no leidas.

#### `markAsRead(Request $request, Notificacion $notification)`

- Rutas principales:
  - `PUT /cliente/notificaciones/{notification}/leer`
  - `PATCH /notifications/{notification}/read`
  - `POST /notifications/{notification}/read`
- Verifica propiedad de la notificacion y marca `read_at`.

#### `markAllAsRead(Request $request)`

- Ruta principal: `PATCH /notifications/read-all`
- Marca todas las no leidas del usuario como leidas.
- Devuelve el numero de registros actualizados.

### `RedAviation\FlightMembershipControlador`

#### `plans()`

- Ruta principal: `GET /flight-membership/plans`
- Lista los planes activos de membresia de vuelo ordenados por precio.
- Serializa cada plan con el servicio de membresias.

#### `checkout(Request $request)`

- Ruta principal: `POST /flight-membership/checkout`
- Valida el plan y crea un checkout de Stripe para comprar la membresia.
- Requiere que Stripe este configurado.
- Devuelve el resultado del servicio, normalmente incluyendo `checkout_session_id`.

#### `current(Request $request)`

- Ruta principal: `GET /flight-membership/current`
- Devuelve la membresia vigente del usuario si existe.

#### `history(Request $request)`

- Ruta principal: `GET /flight-membership/history`
- Devuelve historial de membresias y ledger de beneficios con paginacion.

#### `quotePreview(Request $request, Cotizacion $quote)`

- Ruta principal: `GET /flight-membership/quotes/{quote}/preview`
- Verifica que la cotizacion pertenezca al cliente.
- Devuelve una simulacion del impacto de la membresia sobre esa cotizacion.

## 2. Cliente Red Aviation

### `RedAviation\ClienteControlador`

Este controlador concentra la mayor parte del flujo comercial avanzado del cliente: catalogo de aeronaves, preview de cotizaciones, creacion de solicitudes con seleccion explicita, control de duplicados, pricing canonico, visibilidad para frontend y tracking operativo.

### Funciones publicas

#### `dashboard(Request $request)`

- Ruta principal: `GET /client/dashboard`
- Devuelve metricas simples del cliente:
  - total de solicitudes;
  - operaciones activas.
- Tambien incluye `accessStatus()` del usuario.

#### `indexAircraft(Request $request)`

- Ruta principal: `GET /client/aircraft`
- Lista aeronaves disponibles para cliente con filtros de ruta, pasajeros y ventana operativa.
- Si recibe origen y destino:
  - resuelve aeropuertos activos;
  - calcula tramos de ruta;
  - estima distancia y pricing preliminar por aeronave.
- Usa `AircraftAvailabilityService` para excluir conflictos reales de disponibilidad.
- Ordena priorizando aeronaves basadas en el origen y luego por tarifa.
- Devuelve catalogo serializado, informacion de ruta y metadatos de paginacion.

#### `previewQuotes(Request $request)`

- Ruta principal: `POST /client/quotes/preview`
- Genera una previsualizacion comercial avanzada antes de crear la solicitud.
- Paso a paso:
  - resuelve usuario opcional autenticado;
  - valida acceso comercial o consumo de prueba;
  - normaliza la ruta canonica con multiples tramos si aplica;
  - calcula distancia total;
  - busca candidatos de aeronaves sin conflicto;
  - prioriza opciones basadas en origen y disponibilidad;
  - calcula pricing detallado por candidato;
  - arma payload listo para frontend.
- Si el usuario esta en consumo de prueba, incrementa contadores de uso.
- Es el endpoint mas importante para la experiencia de cotizacion instantanea.

#### `storeFlightRequest(Request $request)`

- Ruta principal: `POST /client/flight-requests`
- Crea la solicitud comercial definitiva del cliente.
- Reglas principales:
  - exige acceso comercial activo, demo o suscripcion vigente;
  - normaliza fechas, tramos y tipo de viaje;
  - elimina del payload cualquier intento del cliente de forzar pricing final;
  - usa `Idempotency-Key` y comparacion de firmas de tramos para evitar duplicados;
  - crea la solicitud en transaccion;
  - persiste legs;
  - hace matching automatico o asigna una opcion seleccionada explicitamente;
  - genera o actualiza una cotizacion aceptada automaticamente segun la seleccion;
  - crea chat protegido para esa solicitud;
  - dispara notificaciones al proveedor.
- Devuelve una respuesta enriquecida con visibilidad orientada a frontend.

#### `indexFlightRequests(Request $request)`

- Ruta principal: `GET /client/flight-requests`
- Lista solicitudes Red Aviation del cliente con:
  - aeronave asignada;
  - ultima operacion;
  - chat;
  - legs;
  - reserva;
  - contrato;
  - ultimo pago.
- Antes de responder intenta normalizar casos donde Stripe ya cobro pero el estado local aun esta pendiente.
- La salida final pasa por `VisibilidadServicio` para quedar en formato consumible por frontend.

#### `showFlightRequest(Request $request, SolicitudVuelo $flightRequest)`

- Ruta principal: `GET /client/flight-requests/{flightRequest}`
- Verifica propiedad de la solicitud.
- Carga detalle completo: assigned aircraft, matches, timeline, chat, legs, reserva, contrato y ultimo pago.
- Normaliza estados pendientes de Stripe antes de responder.
- Entrega el objeto final mediante `VisibilidadServicio`.

#### `tracking(Request $request, Operacion $operation)`

- Ruta principal: `GET /client/operations/{operation}/tracking`
- Verifica que la operacion pertenezca al cliente a traves de la solicitud.
- Devuelve estado y timeline de la operacion.

### Helpers internos de `ClienteControlador`

Los siguientes helpers no se exponen como endpoints, pero son parte central del flujo y vale la pena documentarlos porque explican como se comporta el controlador.

### Grupo: acceso y contexto comercial

#### `resolveCommercialAccessGate($user)`

- Decide si el usuario puede cotizar o crear solicitudes.
- Considera acceso pagado, suscripcion, demo y reglas del plan comercial.
- Tambien define si una previsualizacion debe consumir una cotizacion de prueba.

#### `resolveOptionalApiUser(Request $request)`

- Intenta resolver al usuario autenticado cuando el endpoint puede operar con o sin sesion.
- Se usa principalmente en preview de cotizaciones.

### Grupo: idempotencia y deteccion de duplicados

#### `resolveFlightRequestIdempotencyKey(Request $request, array $validatedData)`

- Obtiene la llave de idempotencia desde header o body.

#### `findExistingFlightRequestByIdempotency(int $clientId, ?string $idempotencyKey)`

- Busca una solicitud ya creada con la misma llave de idempotencia.

#### `findExistingComparableFlightRequest(int $clientId, array $validatedData, bool $lockForUpdate = false)`

- Busca duplicados logicos aunque no exista llave de idempotencia.
- Compara cliente, aeronave asignada, origen, destino, salida, pasajeros, tipo de viaje y firma de tramos.

#### `buildComparableLegsSignatureFromPayload(array $validatedData)`

- Convierte los tramos enviados por el cliente en una firma hash comparable.

#### `buildComparableLegsSignatureFromModel(SolicitudVuelo $request)`

- Genera la misma firma pero desde el modelo ya guardado.

#### `normalizeComparableFlightRequestDateTime(mixed $value)`

- Normaliza fechas a formato comparable para la logica de duplicados.

#### `isFlightRequestIdempotencyUniqueViolation(QueryException $exception)`

- Detecta si una excepcion SQL corresponde al indice unico de idempotencia.

#### `flightRequestSupportsIdempotency()`

- Verifica si la tabla `flight_requests` ya tiene la columna `idempotency_key`.
- Evita romper compatibilidad en entornos donde la migracion aun no exista.

#### `buildStoredFlightRequestResponse(...)`

- Construye la respuesta final de una solicitud ya guardada o reutilizada.
- Carga relaciones, resuelve quote aceptada, chat y salida visible para frontend.

### Grupo: matching, asignacion y validacion de aeronave

#### `assignSelectedMatchToFlightRequest(SolicitudVuelo $solicitud, array $data)`

- Toma el match seleccionado o generado y lo asigna formalmente a la solicitud.
- Calcula pricing del servidor para evitar confiar en montos enviados por el cliente.
- Actualiza proveedor, aeronave, pricing y payload de visibilidad.

#### `ensureAircraftIsAvailableForFlightRequest(int $aircraftId, SolicitudVuelo $solicitud, array $requestData = [])`

- Verifica que la aeronave siga libre para la ventana del vuelo.
- Si existe conflicto devuelve `409`.

#### `assertAircraftEligibleForFlightRequest(Aeronave $aircraft, SolicitudVuelo $solicitud, array $requestData = [])`

- Evalua reglas de elegibilidad operativa de la aeronave para la ruta.
- Usa `AircraftEligibilityService`.
- Si no cumple, rechaza con `409` y codigos de razon.

#### `ensureAcceptedQuoteForFlightRequest(SolicitudVuelo $solicitud)`

- Garantiza que exista una cotizacion `accepted` alineada con la opcion seleccionada por el cliente.
- Si ya existe la actualiza; si no, la crea.

### Grupo: normalizacion de estados de pago

#### `normalizeStripePendingPaymentState(SolicitudVuelo $flightRequest)`

- Corrige inconsistencias cuando Stripe ya dio senales de pago pero la solicitud o reserva siguen en estado pendiente.
- Actualiza solicitud, reserva y pago, y ademas bloquea la aeronave como reserva pagada.

### Grupo: aeropuertos y ruta

#### `findActiveAirport(string $code)`

- Busca un aeropuerto activo por ICAO, IATA y columnas alternativas compatibles.
- Mantiene cache interna para no repetir consultas.

#### `activeAirportSearchColumns()`

- Define dinamicamente las columnas por las que se debe buscar un aeropuerto.

#### `distanceKm(...)` y `distanceNm(...)`

- Calculan distancias geograficas entre dos puntos para pricing y reglas operativas.

#### `quoteLegs(...)`

- Construye los tramos que se usaran para cotizar entre origen y destino.

#### `normalizeRouteLegDefinitions(array $data)`

- Convierte el payload del frontend en una lista uniforme de legs.

#### `normalizeExplicitLegDefinitions(mixed $legs)`

- Normaliza legs enviados explicitamente por el cliente.

#### `extractLegDurationFields(array $payload)`

- Extrae duraciones manuales o auxiliares del tramo.

#### `extractAirportCode(array $payload, array $keys)`

- Busca el codigo de aeropuerto correcto dentro de varios posibles nombres de campo.

#### `quoteLegPayload(...)`

- Construye el payload final de un leg listo para pricing.

### Grupo: pricing y presentacion comercial

#### `previewPricingForAircraft(...)`

- Ejecuta pricing preliminar para una aeronave dentro del catalogo.

#### `buildPreviewQuotesForCandidates(...)`

- Toma la lista de candidatos y arma el bloque de cotizaciones preview.

#### `preparePreviewCandidatesForEvaluation(...)`

- Enriquecimiento previo de candidatos para que el pricing y la disponibilidad trabajen con el mismo contexto.

#### `buildPreviewQuotePayload(...)`

- Crea el payload final de cada opcion de cotizacion visible para frontend.

#### `calculateLegacyPricing(...)`

- Mantiene compatibilidad con una estrategia anterior de pricing.

#### `calculateLegPricing(...)`

- Calcula pricing detallado por tramo.

#### `resolveCruiseSpeedKmh(Aeronave $aircraft)`

- Resuelve la velocidad comercial base para tiempos de vuelo.

#### `resolveManualLegDuration(array $legContext)`

- Permite usar duraciones manuales cuando el frontend o la operacion las envian.

#### `normalizeCruiseCategory(mixed $value)`

- Normaliza categoria o banda comercial usada para velocidad.

#### `calculateClimbDescentMinutes(...)`

- Suma minutos operativos de ascenso y descenso por categoria o configuracion.

#### `normalizePricingCategory(mixed $value)`

- Estandariza la categoria de pricing usada en reglas comerciales.

#### `resolveAircraftClimbDescentBaseMinutes(Aeronave $aircraft)`

- Resuelve minutos base de ascenso/descenso de la aeronave.

#### `resolveMinimumHours(...)`

- Resuelve horas minimas cobrables por categoria o distancia.

#### `resolveCommercialMarginRate(Aeronave $aircraft, array $categoryPricingRule)`

- Define el margen comercial aplicado al precio.

#### `resolveAircraftBaseAirport(Aeronave $aircraft)`

- Obtiene aeropuerto base real de la aeronave.

#### `airportsMatch(...)` y `airportMatchesCode(...)`

- Helpers de comparacion para reglas de base y ruta.

#### `emptyLegPricing()`

- Devuelve una estructura vacia estandar para pricing de tramos.

#### `airportFromPayload(array $payload)`

- Convierte el payload de aeropuerto a modelo o contexto usable.

#### `countAirportsForFees(array $legs)`

- Cuenta aeropuertos relevantes para cargos de aeropuerto.

#### `calculateOvernightNights(array $legs)` y `resolveOvernightNights(array $legs, array $requestData = [])`

- Calculan pernoctas aplicables para costos.

#### `operationalBufferHours(float $distanceNm)`

- Aplica colchones operativos de tiempo.

#### `roundUpQuarterHours(float $hours)`

- Redondea horas a cuartos cuando la politica comercial lo exige.

#### `isInternationalLeg(Aeropuerto $originAirport, Aeropuerto $destinationAirport)`

- Marca si un tramo cruza fronteras y puede requerir tratamiento especial.

#### `normalizeTripType(?string $tripType)` y `resolveQuoteTripType(array $data)`

- Normalizan el tipo de viaje real: one way, round trip o multi leg.

#### `shouldReturnToOrigin(array $data)` y `shouldTreatAsOpenRoute(array $data)`

- Determinan si el viaje se debe cerrar al origen o tratar como ruta abierta.

#### `shouldIncludeIva(array $data)`

- Define si el calculo incorpora IVA.

#### `shouldApplyAirportExpenses(array $data)`

- Define si se incorporan gastos aeroportuarios.

#### `shouldApplyCommercialMargin(array $data)`

- Define si se aplica margen comercial.

#### `resolveCommercialHourlyRate(mixed $value)` y `resolveHourlyRateSource(Aeronave $aircraft)`

- Determinan tarifa por hora y de donde sale.

#### `resolveAirportExpenseForAircraft(Aeronave $aircraft)`

- Resuelve gasto aeroportuario default o propio de la aeronave.

#### `resolveAirportExpenseContext(Aeronave $aircraft, array $legs)` y `resolveAirportExpenseRule(Aeronave $aircraft, array $legs)`

- Seleccionan la mejor regla de gasto aeroportuario para la ruta.

#### `airportExpenseRulesTableExists()` y `activeAirportExpenseRules()`

- Gestionan existencia y cache de reglas de gasto aeroportuario.

#### `buildRouteSignature(array $legs)`

- Crea una firma de ruta para reglas, caches o comparaciones.

#### `resolveMinimumHoursSource(Aeronave $aircraft, float $distanceKm, float $minimumHours)`

- Documenta de donde provino la hora minima aplicada.

### Grupo: saneamiento del payload cliente

#### `stripClientPricingFields(array $data)`

- Quita del payload campos de pricing que el cliente no debe controlar.

#### `extractIgnoredClientPricingFields(array $data)`

- Extrae y registra los montos que el cliente envio pero que el backend ignoro.

#### `resolveServerPricingForSelectedAircraft(Aeronave $aircraft, SolicitudVuelo $solicitud, array $requestData)`

- Recalcula el pricing oficial del servidor para la aeronave elegida.

#### `storeFlightRequestLegs(SolicitudVuelo $solicitud, array $data)`

- Persiste legs normalizados dentro de la solicitud.

#### `resolveLegDepartureDatetime(array $leg, string $fallbackDepartureDatetime)`

- Resuelve fecha/hora definitiva de salida de cada tramo.

#### `resolveLegDistanceKm(string $originCode, string $destinationCode)`

- Calcula o intenta recuperar la distancia entre dos aeropuertos.

### Grupo: payloads para frontend

#### `aircraftIsBasedAtOrigin(Aeronave $aircraft, Aeropuerto $originAirport)`

- Detecta si la aeronave sale desde el aeropuerto ideal del cliente.

#### `matchReason(bool $basedAtOrigin, ?string $baseAirportCode)`

- Genera el motivo comercial visible de por que una aeronave fue sugerida.

#### `responseTime(bool $basedAtOrigin)`

- Devuelve una expectativa comercial de tiempo de respuesta o disponibilidad.

#### `airportPreviewPayload(Aeropuerto $airport)`

- Serializa aeropuerto para vistas de preview.

#### `aircraftPreviewPayload(Aeronave $aircraft)`

- Serializa aeronave para vistas de preview.

#### `aircraftCatalogPayload(...)`

- Construye el bloque final de catalogo que consume el frontend cliente.

#### `normalizeAircraftCategory(mixed $value)`

- Estandariza categorias de aeronave.

#### `resolveCategoryPricingRule(Aeronave $aircraft)`

- Obtiene la regla comercial por categoria para esa aeronave.

#### `resolveMinimumRoutePrice(Aeronave $aircraft, float $distanceKm, array $categoryPricingRule)`

- Calcula el precio minimo de ruta aplicable antes de montar el total final.

## Siguiente paso sugerido

Este archivo cubre primero el flujo de cliente, que era la prioridad. Si se quiere continuar con la misma estructura, el siguiente bloque natural seria documentar:

1. flujo proveedor u operador;
2. flujo admin;
3. servicios transversales de pagos, contratos y disponibilidad.
