# Docs

## Como se saca el calculo del precio

Este documento explica como el backend calcula el precio de un vuelo en el flujo Red Aviation. La logica oficial vive principalmente en:

- `app/Servicios/Vuelos/FlightPricingService.php`
- `app/Servicios/Vuelos/FlightDurationService.php`
- `app/Servicios/Pagos/PaymentFeeCalculationServicio.php`
- `app/Servicios/Aeronaves/AircraftRepositioningService.php`

## Resumen corto

El backend no confia en los montos que mande el frontend. El precio final se vuelve a calcular del lado del servidor con esta secuencia:

1. Se sanea el payload del cliente y se eliminan campos de pricing no confiables.
2. Se normaliza la ruta canonica y sus tramos.
3. Se calculan horas por tramo segun distancia y velocidad de la aeronave.
4. Se aplican horas minimas por categoria si la ruta es corta.
5. Se calcula el costo base por hora.
6. Se aplica precio minimo de ruta si corresponde.
7. Se agregan costos operativos extra:
   - reposicionamiento;
   - regreso a base;
   - gastos aeroportuarios;
   - overnight.
8. Se aplica margen comercial si esta habilitado.
9. Se agregan comisiones de cobro y administrativas.
10. Se calcula IVA.
11. Se obtiene `total_amount`.

## 1. Punto de entrada oficial

La formula principal esta en:

- `FlightPricingService::calculate()`
- `FlightPricingService::calculateForAircraft()`

`calculate()` recibe:

- la aeronave;
- la ruta canonica;
- el payload del cliente;
- una closure que ya no se usa para el calculo final.

Luego llama a `calculateForAircraft()`, que hace el calculo real.

## 2. El backend no acepta pricing del cliente

Antes de calcular, el servicio limpia el payload usando:

- `FlightPricingService::sanitizeClientPayload()`

Campos que el backend elimina si vienen del frontend:

- `hourly_rate`
- `base_price`
- `subtotal`
- `tax`
- `taxes`
- `iva`
- `total`
- `total_amount`
- `final_price`
- `selected_card_price`
- `pricing_context`
- `aircraft_snapshot`
- `billable_hours`
- `distance_km`
- `distance_nm`
- `duration_minutes`
- `pricing_formula_version`

Eso significa que el frontend puede pedir una cotizacion, pero no puede imponer el resultado.

## 3. Como se calculan las horas por tramo

Cada tramo pasa por:

- `FlightDurationService::calculateLeg()`

### 3.1 Velocidad base

Primero se obtiene la velocidad:

- si la aeronave tiene `speed_knots`, usa ese valor;
- si no, convierte `speed_kmh` a nudos;
- si tampoco existe, usa una velocidad por categoria basada en Mach.

Categorias usadas:

- `Helicoptero`
- `Turboprop`
- `Light Jet`
- `Mid Jet`
- `Heavy Jet`
- `Ultra Long Range`

### 3.2 Tiempo directo

La formula base del tramo es:

```text
direct_hours = distance_nm / speed_knots
```

### 3.3 Minutos operativos

Luego calcula tiempo operativo:

```text
operational_minutes =
max(
  (direct_hours * 60 * operational_factor) + fixed_minutes_per_leg + climb_descent_minutes,
  minimum_minutes_per_leg
)
```

De aqui salen:

- `direct_air_time_hours`
- `real_flight_hours`
- `operational_minutes`

### 3.4 Horas facturables por tramo

La parte comercial del tramo usa:

```text
billable_hours = max(round(direct_hours * 4) / 4, minimum_hours)
```

Eso significa:

- el tiempo directo se redondea a cuartos de hora;
- si la categoria tiene un minimo mayor, se usa el minimo.

### 3.5 Horas minimas por categoria

`FlightDurationService::minimumHours()` aplica estas horas minimas cuando la ruta es corta, es decir, menor a `300 km`:

- `Helicoptero`: `1.0`
- `Turboprop`: `1.5`
- `Light Jet`: `2.0`
- `Mid Jet`: `2.5`
- `Heavy Jet`: `3.0`
- `Ultra Long Range`: `4.0`

Si la ruta mide `300 km` o mas, el minimo fallback puede ser `0`.

## 4. Como se arma la ruta completa

En `FlightPricingService::calculateForAircraft()` se suman todos los tramos:

- `route_direct_hours`
- `route_operational_hours`
- `route_display_hours`
- `route_pricing_hours`
- `route_billable_hours`

Los modos de tiempo que pueden afectar la lectura de horas son:

- `direct`
- `direct_plus_climb`
- `operational`

Se resuelven con:

- `resolveTimeMode()`
- `resolveLegHoursByMode()`

Pero el dato importante para cobrar termina siendo `route_billable_hours`.

## 5. Redondeo de horas

El redondeo final usa:

- `resolveRoundingMode()`
- `applyRoundingMode()`

Modos soportados:

- `none`
- `quarter_nearest`
- `quarter_up`

Regla por default:

- si el origen del calculo es `distance_speed`, el default es `quarter_nearest`;
- si no, el default es `none`.

## 6. Tarifa por hora

La tarifa por hora se toma de:

- `aircraft.hourly_rate`

La resuelve:

- `resolveCommercialHourlyRate()`

Regla especial:

- si `hourly_rate` es mayor a `0` pero menor a `100`, el sistema lo multiplica por `1000`.

Eso existe para soportar datos legacy o capturas en miles.

Ejemplo:

```text
hourly_rate = 8.5
tarifa real = 8500
```

## 7. Costo base del vuelo

Con las horas facturables de la ruta se calcula:

```text
clientFlightCost = clientBillableHours * hourlyRate
```

Donde:

- `clientBillableHours = max(routeBillableHours, appliedMinimumHours, 0)`

Y:

- `appliedMinimumHours` puede venir de la aeronave;
- si la aeronave no tiene `minimum_hours`, se usa el fallback por categoria.

## 8. Precio minimo de ruta

Luego se protege el precio minimo de la ruta.

Se revisa primero:

- `aircraft.minimum_route_price`

Si no existe, usa regla por categoria desde:

- `category_pricing_rules`
- o el fallback interno `AIRCRAFT_CATEGORY_MINIMUM_PRICE`

Valores default por categoria:

- `Helicoptero`: `2200`
- `Turboprop`: `2800`
- `Light Jet`: `3800`
- `Mid Jet`: `4800`
- `Heavy Jet`: `7000`

La variable final es:

```text
customerFlightCost = max(clientFlightCost, minimumRoutePrice)
```

## 9. Reposicionamiento y regreso a base

Si el contexto operativo indica que aplica pricing de reposicionamiento:

- `operational_context.apply_repositioning_pricing = true`

Entonces el servicio puede incluir:

- `repositioning`
- `return_to_base`

Estos segmentos traen:

- `distance_km`
- `distance_nm`
- `billable_hours`
- `cost`

Ademas, cada uno puede o no sumarse a horas cobrables segun flags:

- `include_repositioning_in_billed_hours`
- `include_return_to_base_in_billed_hours`

Por default estos dos vienen en `true` cuando hay pricing de reposicionamiento.

## 10. Overnight

El backend calcula noches de pernocta con:

- `resolveOvernightNights()`
- `calculateOvernightNights()`

Reglas:

- si el request manda `overnights` o `overnight_nights`, usa ese valor;
- si no, cuenta dias entre salidas de tramos consecutivos.

El costo por noche sale de:

- `aircraft.overnight_fee`
- o si no existe, `hourly_rate / 2`

Formula:

```text
overnightCost = overnightFee * overnightNights
```

Si `include_overnight_in_billed_hours = true`, tambien suma:

```text
overnightHours = overnightNights * 0.5
```

## 11. Gastos aeroportuarios

Los gastos aeroportuarios se activan con:

- `airport_expenses = true`

Si no se manda ese campo, el default es `true`.

La resolucion ocurre en:

- `resolveAirportExpenseContext()`
- `resolveAirportExpenseRule()`

Orden de prioridad:

1. regla activa en tabla `airport_expense_rules`;
2. `aircraft.airport_expenses_usd`;
3. fallback default `1000 USD`.

Regla especial:

- si `aircraft.airport_expenses_usd` es mayor a `0` pero menor a `100`, lo multiplica por `1000`.

## 12. Subtotal operativo

Una vez calculados los componentes, el backend arma:

```text
subtotalOperative =
  customerFlightCost
  + repositioningCost
  + returnToBaseCost
  + airportExpenses
  + overnightCost
```

Pero solo suma `repositioningCost` o `returnToBaseCost` si esos componentes estan habilitados para pricing operativo.

## 13. Ajuste por precio minimo

Despues aplica otra proteccion:

```text
subtotalBeforeMargin = max(subtotalOperative, minimumRoutePrice)
```

Y calcula:

```text
minimumAdjustment = max(subtotalBeforeMargin - subtotalOperative, 0)
```

Esto sirve para identificar cuanto se tuvo que empujar el subtotal para respetar el minimo.

## 14. Margen comercial

El margen solo se aplica si:

- `apply_margin = true`

Si el campo no viene, el default es `false`.

La tasa se resuelve en:

- `resolveCommercialMarginRate()`

Orden:

1. `provider.margin_percent`
2. `category_pricing_rules.redsky_markup`
3. fallback por categoria

Fallbacks internos:

- `Helicoptero`: `20%`
- `Turboprop`: `20%`
- `Light Jet`: `22%`
- `Mid Jet`: `25%`
- `Heavy Jet`: `30%`

Formula:

```text
marginAmount = subtotalBeforeMargin * marginRate
subtotalBeforeFees = subtotalBeforeMargin + marginAmount
```

## 15. Comisiones Stripe y administrativas

Se calculan con:

- `PaymentFeeCalculationServicio::flightBreakdown()`

Tasas actuales:

- `stripe_fee = 3.6%`
- `administrative_fee = 3.0%`

Formula:

```text
stripeFee = subtotalBeforeFees * 0.036
administrativeFee = subtotalBeforeFees * 0.03
subtotal = subtotalBeforeFees + stripeFee + administrativeFee
```

## 16. IVA

El IVA depende de:

- `include_iva`

Si el campo no viene, el default es `true`.

Tasa actual:

- `16%`

Importante:

- el IVA no se calcula sobre los gastos aeroportuarios;
- primero se resta `airportExpenses` del subtotal.

Formula:

```text
taxableSubtotal = max(subtotal - airportExpenses, 0)
taxes = taxableSubtotal * 0.16
```

## 17. Total final

La formula final es:

```text
totalAmount = subtotal + taxes
```

En el payload final aparece como:

- `total_amount`
- `total`
- `final_price`
- `selected_card_price`

Todos esos campos salen del mismo total calculado por backend.

## 18. Formula completa simplificada

Version simplificada del flujo:

```text
1. horas_tramo = max(redondeo_cuarto_hora(tiempo_directo), minimo_categoria)
2. horas_ruta = suma(horas_tramo)
3. costo_vuelo = max(horas_ruta * tarifa_hora, minimo_ruta)
4. subtotal_operativo =
   costo_vuelo
   + reposicionamiento
   + regreso_base
   + gastos_aeropuerto
   + overnight
5. subtotal_before_margin = max(subtotal_operativo, minimo_ruta)
6. subtotal_before_fees = subtotal_before_margin + margen
7. subtotal = subtotal_before_fees + stripe_fee + administrative_fee
8. taxable_subtotal = subtotal - gastos_aeropuerto
9. taxes = taxable_subtotal * iva
10. total_amount = subtotal + taxes
```

## 19. Campos de salida mas importantes

El backend devuelve muchos campos, pero los mas utiles para entender el calculo son:

- `hourly_rate`
- `route_billable_hours`
- `final_billable_hours`
- `billable_hours`
- `minimum_hours`
- `minimum_route_price`
- `base_price`
- `flight_cost`
- `airport_expenses`
- `repositioning_cost`
- `return_to_base_cost`
- `overnight_cost`
- `margin_amount`
- `stripe_fee`
- `administrative_fee`
- `subtotal_before_margin`
- `subtotal`
- `taxable_subtotal`
- `tax`
- `taxes`
- `total_amount`
- `pricing_formula_version`
- `base_price_formula.expression`

## 20. Ejemplo conceptual

Ejemplo simple:

```text
tarifa por hora = 8,000
horas facturables ruta = 2.5
minimo ruta = 3,800
reposicionamiento = 500
gastos aeropuerto = 1,000
overnight = 0
margen = 20%
```

Paso a paso:

```text
costo_vuelo = 2.5 * 8,000 = 20,000
subtotal_operativo = 20,000 + 500 + 1,000 = 21,500
subtotal_before_margin = max(21,500, 3,800) = 21,500
margen = 21,500 * 0.20 = 4,300
subtotal_before_fees = 25,800
stripe_fee = 25,800 * 0.036 = 928.80
administrative_fee = 25,800 * 0.03 = 774.00
subtotal = 27,502.80
taxable_subtotal = 27,502.80 - 1,000 = 26,502.80
iva = 26,502.80 * 0.16 = 4,240.45
total_amount = 31,743.25
```

## 21. Donde verlo en el codigo

Referencias mas utiles:

- `app/Servicios/Vuelos/FlightPricingService.php`
- `app/Servicios/Vuelos/FlightDurationService.php`
- `app/Servicios/Pagos/PaymentFeeCalculationServicio.php`
- `app/Http/Controladores/RedAviation/ClienteControlador.php`

Si quieres depurar un caso real, el mejor bloque a revisar es:

- `debug_pricing`
- `pricing`
- `base_price_formula`
- `route_snapshot`
- `duration_snapshot`

## 22. Idea clave

El precio final no sale de un solo numero. Sale de combinar:

- tiempo facturable real;
- reglas minimas por categoria;
- precio minimo de ruta;
- extras operativos;
- margen comercial;
- comisiones de cobro;
- IVA.

Por eso el dato correcto para frontend o auditoria siempre debe ser `pricing_context.total_amount` o `total_amount` calculado por backend.



MMTO->MMOX
MMOX->MMMY
MMMY->MMTO



La ruta corresponde a:

1. **MMTO → MMOX**

   * **MMTO:** Toluca, Estado de México
   * **MMOX:** Oaxaca (Aeropuerto Internacional de Oaxaca "Xoxocotlán")

2. **MMOX → MMMY**

   * **MMOX:** Oaxaca
   * **MMMY:** Monterrey, Nuevo León (Aeropuerto Internacional General Mariano Escobedo)

3. **MMMY → MMTO**

   * **MMMY:** Monterrey
   * **MMTO:** Toluca

### Itinerario

* **Toluca → Oaxaca**
* **Oaxaca → Monterrey**
* **Monterrey → Toluca**

> **Nota:** **MMOX no es Morelos ni Morelia.** El código ICAO **MMOX** corresponde a **Oaxaca (Xoxocotlán)**. Si buscabas **Morelia**, el código ICAO es **MMMM**.
