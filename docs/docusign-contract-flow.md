# Integracion DocuSign Para Contratos De Reserva

## Objetivo

Este documento describe la integracion de DocuSign sobre el flujo actual de contratos de reserva del backend Laravel. La implementacion reutiliza la estructura existente de `reservation_contracts` y extiende el proceso actual de firma manual para soportar firma embebida, webhook de confirmacion y redireccion al frontend.

## Alcance

La integracion cubre:

- Generacion de contrato PDF desde el backend.
- Envio del contrato a DocuSign mediante JWT.
- Apertura de firma embebida para el cliente.
- Recepcion de webhook de DocuSign.
- Actualizacion del estado del contrato.
- Descarga y resguardo del PDF firmado.
- Habilitacion del flujo de pago una vez firmando el contrato.

No cubre:

- Implementacion del frontend Vue.
- Configuracion de Connect en DocuSign fuera del backend.
- Manejo de certificado de firma y auditoria avanzada adicional.

## Arquitectura General

La integracion se construye sobre la tabla existente:

```text
reservation_contracts
```

No se crea una tabla nueva para contratos. En su lugar, se amplian los campos del contrato actual para soportar metadata de DocuSign.

## Modelo De Datos

### Tabla principal

La tabla `reservation_contracts` es la fuente de verdad del contrato de reserva.

Campos relevantes para DocuSign:

```text
docusign_envelope_id
docusign_status
signer_name
signer_email
client_user_id
contract_pdf_path
signed_pdf_path
sent_at
completed_at
last_webhook_payload
```

### Migracion aplicada

Archivo:

- [2026_06_01_120000_agregar_campos_docusign_a_contratos_reserva.php](/Users/redaviation/Documents/SKYGRUP/UBERAVIONES/BACKEND%20UBER%20AVIONES/database/migrations/2026_06_01_120000_agregar_campos_docusign_a_contratos_reserva.php:1)

Esta migracion modifica `reservation_contracts` para soportar el ciclo completo de DocuSign.

### Campos opcionales recomendados

Si se requiere mas trazabilidad documental, se recomienda considerar estos campos adicionales:

```sql
ALTER TABLE reservation_contracts
ADD COLUMN docusign_completed_pdf_url VARCHAR(500) NULL,
ADD COLUMN docusign_certificate_path VARCHAR(500) NULL,
ADD COLUMN docusign_error TEXT NULL;
```

Uso sugerido:

- `docusign_completed_pdf_url`: URL publica o privada del PDF final firmado.
- `docusign_certificate_path`: ruta del certificado de firma emitido por DocuSign.
- `docusign_error`: ultimo error funcional al enviar, consultar o cerrar el envelope.

## Servicios Implementados

### 1. Servicio de DocuSign

Archivo:

- [DocuSignServicio.php](/Users/redaviation/Documents/SKYGRUP/UBERAVIONES/BACKEND%20UBER%20AVIONES/app/Servicios/Contratos/DocuSignServicio.php:1)

Responsabilidades:

- Validar configuracion requerida.
- Autenticarse con JWT.
- Crear envelope para firma embebida.
- Crear URL de firma embebida.
- Consultar estado del envelope.
- Descargar PDF firmado combinado.
- Construir URL de retorno al frontend.

### 2. Servicio de PDF del contrato

Archivo:

- [ContratoPdfServicio.php](/Users/redaviation/Documents/SKYGRUP/UBERAVIONES/BACKEND%20UBER%20AVIONES/app/Servicios/Contratos/ContratoPdfServicio.php:1)

Responsabilidades:

- Generar el PDF del contrato desde la vista Blade existente.
- Guardar el contrato generado en storage.
- Guardar el PDF firmado descargado desde DocuSign.

### 3. Servicio de cierre de contrato

Archivo:

- [ContratoReservaServicio.php](/Users/redaviation/Documents/SKYGRUP/UBERAVIONES/BACKEND%20UBER%20AVIONES/app/Servicios/Contratos/ContratoReservaServicio.php:1)

Responsabilidades:

- Marcar un contrato como firmado.
- Actualizar la reserva al estado correspondiente.
- Crear o reutilizar la orden de pago pendiente.
- Centralizar la transicion compartida entre firma manual y firma por DocuSign.

## Controladores

### Controlador de reservas

Archivo:

- [ReservaControlador.php](/Users/redaviation/Documents/SKYGRUP/UBERAVIONES/BACKEND%20UBER%20AVIONES/app/Http/Controladores/ReservaControlador.php:1)

Puntos clave:

- Mantiene el flujo actual de contratos asociado a `reservas`.
- Expone el inicio de firma embebida mediante DocuSign.
- Conserva el flujo de firma manual existente.

Endpoint principal DocuSign:

```text
POST /api/v1/cliente/reservas/{reservation}/contrato/docusign
```

### Controlador de webhook DocuSign

Archivo:

- [DocuSignWebhookControlador.php](/Users/redaviation/Documents/SKYGRUP/UBERAVIONES/BACKEND%20UBER%20AVIONES/app/Http/Controladores/DocuSignWebhookControlador.php:1)

Responsabilidades:

- Recibir eventos de DocuSign.
- Ubicar el contrato por `docusign_envelope_id`.
- Actualizar `docusign_status`.
- Descargar el PDF firmado.
- Completar la firma del contrato en el sistema.

Endpoint:

```text
POST /api/v1/public/docusign/webhook
```

## Rutas Del Backend

Las rutas relevantes del flujo quedaron integradas al dominio funcional de reservas.

```text
GET  /api/v1/cliente/reservas/{reservation}/contrato
GET  /api/v1/cliente/reservas/{reservation}/contrato/pdf
POST /api/v1/cliente/reservas/{reservation}/contrato/generar
POST /api/v1/cliente/reservas/{reservation}/contrato/firmar
POST /api/v1/cliente/reservas/{reservation}/contrato/docusign
POST /api/v1/public/docusign/webhook
```

Este diseno es preferible a un modulo paralelo de `contracts`, porque aprovecha el contexto real de negocio ya existente alrededor de la reserva.

## Flujo Funcional

### Flujo de contrato con DocuSign

```text
Reserva creada
↓
Contrato generado
↓
Backend genera PDF
↓
Backend crea envelope en DocuSign
↓
Backend guarda docusign_envelope_id y docusign_status = sent
↓
Cliente firma desde firma embebida
↓
DocuSign envia webhook
↓
Backend descarga PDF firmado
↓
Backend marca contrato completado
↓
Backend habilita orden de pago
```

### Flujo de firma manual existente

El sistema mantiene compatibilidad con la firma manual actual:

```text
generated
signed
```

### Flujo recomendado para DocuSign

Para DocuSign se recomienda operar con estos estados:

```text
generated
sent
completed
declined
voided
error
```

### Regla de interpretacion

Se recomienda tratar:

- `status` como estado funcional interno del contrato.
- `docusign_status` como estado del envelope en DocuSign.

Esto evita mezclar la semantica interna del negocio con la semantica del proveedor externo.

## Frontend A Backend

Para la comunicacion desde el frontend hacia el backend, deben considerarse los siguientes puntos:

### URL de frontend registrada en backend

Variables relevantes:

```env
FRONTEND_URL=https://redskyg.com/renta
APP_FRONTEND_URL=https://redskyg.com/renta
APP_BACKEND_URL=https://uber-aviones.onrender.com
DOCUSIGN_RETURN_PATH=/cliente/contrato/
```

### CORS

El backend debe permitir los origenes reales del frontend:

```env
CORS_ALLOWED_ORIGINS=https://redskyg.com,https://www.redskyg.com,https://uber-aviones-web.vercel.app,http://localhost:5173,http://127.0.0.1:5173
```

### Autenticacion

El backend acepta:

- `Authorization: Bearer <token>`
- cookie `red_aviation_session`

Para frontend web cross-site, si se usa cookie, el cliente debe enviar `credentials: 'include'`.

## Backend A Frontend

La redireccion de retorno tras la firma embebida se construye con:

```text
APP_FRONTEND_URL + DOCUSIGN_RETURN_PATH + ?contract_id={id}
```

Ejemplo real:

```text
https://redskyg.com/renta/cliente/contrato/?contract_id=123
```

Esto ya fue validado desde el servicio actual.

## Variables De Entorno En Render

Configuracion minima recomendada:

```env
DOCUSIGN_INTEGRATION_KEY=
DOCUSIGN_USER_ID=
DOCUSIGN_ACCOUNT_ID=
DOCUSIGN_BASE_PATH=https://demo.docusign.net/restapi
DOCUSIGN_OAUTH_BASE_PATH=account-d.docusign.com
DOCUSIGN_PRIVATE_KEY=
DOCUSIGN_WEBHOOK_SECRET=

APP_FRONTEND_URL=https://redskyg.com/renta
APP_BACKEND_URL=https://uber-aviones.onrender.com
DOCUSIGN_RETURN_PATH=/cliente/contrato/
```

Notas:

- `DOCUSIGN_PRIVATE_KEY` puede cargarse directamente en Render.
- Si se usa una sola linea, los saltos deben representarse como `\n`.
- El backend tambien soporta `DOCUSIGN_PRIVATE_KEY_PATH`, pero en Render es preferible `DOCUSIGN_PRIVATE_KEY`.

## Requerimientos Del Frontend Vue

Se recomienda implementar al menos:

- `src/services/contractApi.js`
- boton `Firmar contrato`
- vista `renta/cliente/contrato/`
- lectura de `contract_id` desde query string
- consulta de estado del contrato
- bloqueo del pago hasta confirmar firma valida

### Reglas funcionales del frontend

- Mostrar acceso a firma solo si existe reserva y contrato.
- Si el backend responde `signing_url`, redirigir al flujo de firma.
- Al volver a `renta/cliente/contrato/`, consultar el contrato por `contract_id` o por reserva.
- No habilitar pago mientras el contrato no este firmado.

## Consideraciones De Persistencia

### Que se guarda hoy

Cuando la firma es manual:

- Se guarda el contrato en `reservation_contracts`.
- Se guarda `terms_snapshot`.
- Se guarda `client_signature.data_url` dentro de `terms_snapshot`.
- No necesariamente se guarda un PDF fisico firmado.

Cuando la firma llega por DocuSign:

- Se guarda `docusign_envelope_id`.
- Se actualiza `docusign_status`.
- Se guarda el webhook recibido.
- Se descarga y guarda el PDF firmado.

### Donde se guarda

- Base de datos: tabla `reservation_contracts`
- Archivos PDF: storage local del backend, bajo el disk configurado en Laravel

## Riesgos Y Buenas Practicas

- No almacenar llaves privadas reales en `.env.example`.
- No mezclar estados de firma manual con estados externos sin una convencion clara.
- Limpiar cache de configuracion tras actualizar variables:

```bash
php artisan config:clear
```

- Validar que DocuSign Connect apunte al webhook productivo correcto.
- Si se expusieron secretos en algun momento, rotarlos.

## Recomendacion Final

La implementacion correcta para este proyecto es reutilizar `reservation_contracts` y mantener la integracion de DocuSign dentro del flujo de `reservas`, en lugar de crear un modulo paralelo de contratos.

Esto permite:

- Menor duplicacion de logica.
- Mejor trazabilidad de reserva, contrato y pago.
- Menor complejidad operativa.
- Integracion natural con el flujo existente del cliente.
