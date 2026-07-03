<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $contract->contract_code ?: 'Contrato de reserva' }}</title>
    <style>
        @page {
            margin: 22px 24px 28px;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #1a1f29;
            font-size: 9px;
            line-height: 1.38;
            background: #ffffff;
        }
        .page {
            width: 100%;
            border: 1px solid #e1dccf;
            background: #fffdf8;
            padding: 10px 10px 14px;
        }
        .top-nav {
            width: 100%;
            margin-bottom: 0;
            color: #5f5b55;
            font-size: 7px;
            text-transform: uppercase;
            padding: 4px 6px 8px;
        }
        .top-nav td {
            vertical-align: middle;
        }
        .top-nav .brand {
            width: 18%;
            font-weight: 700;
        }
        .top-nav .menu {
            width: 52%;
            text-align: center;
        }
        .top-nav .menu span {
            margin: 0 10px;
        }
        .top-nav .user {
            width: 30%;
            text-align: right;
        }
        .frame {
            border: 1px solid #e5dfd4;
            background: #fffdf8;
            padding: 10px 12px 12px;
        }
        .hero-banner {
            width: 100%;
            background: transparent;
            padding: 0;
        }
        .banner-box {
            background: #1d2430;
            border: 1px solid #3b4350;
            padding: 12px 14px 8px;
        }
        .banner-box img {
            display: block;
            width: 100%;
            max-width: none;
        }
        .banner-caption {
            margin-top: 8px;
            color: #efe9df;
            font-size: 6px;
            line-height: 1.3;
            font-weight: 700;
        }
        .hero-content {
            padding: 10px 0 0;
        }
        .hero-head {
            width: 100%;
            border-collapse: collapse;
        }
        .hero-head td {
            vertical-align: middle;
        }
        .reservation-strip {
            margin: 0 0 10px;
            color: #8d7d61;
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .hero-badge-wrap {
            text-align: right;
        }
        .hero-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 999px;
            background: #f1e4c6;
            color: #9a7423;
            font-size: 7px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
        }
        .title {
            margin: 0;
            font-size: 20px;
            line-height: 1.2;
            color: #20242f;
            font-family: DejaVu Sans, sans-serif;
        }
        .route {
            margin: 5px 0 8px;
            color: #6b6256;
            font-size: 9px;
            font-weight: 700;
        }
        .hero-metrics {
            margin: 0 0 10px;
            color: #6b6861;
            font-size: 6px;
            font-weight: 700;
        }
        .hero-metrics span {
            margin-right: 14px;
            white-space: nowrap;
        }
        .intro {
            margin: 0 0 10px;
            color: #5d5b57;
            font-size: 7px;
            line-height: 1.45;
        }
        .summary-grid,
        .anexo-table,
        .compact-table,
        .pricing-table,
        .accounts-table,
        .signature-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .summary-grid td,
        .accounts-table td,
        .signature-table td {
            vertical-align: top;
        }
        .summary-grid td {
            padding: 3px;
        }
        .signature-table-single td {
            width: 100%;
        }
        .gutter-right {
            padding-right: 4px;
        }
        .gutter-left {
            padding-left: 4px;
        }
        .card {
            border: 1px solid #e5dfd4;
            background: #ffffff;
            border-radius: 8px;
            padding: 8px 9px;
        }
        .label {
            color: #9a8d78;
            font-size: 6px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .metric {
            margin-top: 4px;
            font-size: 9px;
            font-weight: 700;
            color: #212734;
        }
        .hint {
            margin-top: 4px;
            color: #7d786f;
            font-size: 6px;
        }
        .section-title {
            margin: 12px 0 6px;
            color: #2b2f37;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .section-rule {
            height: 1px;
            background: #e5dfd4;
            margin-bottom: 6px;
        }
        .section-band {
            margin: 12px 0 8px;
            padding: 6px 10px;
            text-align: center;
            background: #f4efe5;
            border-top: 1px solid #ddd3c1;
            border-bottom: 1px solid #ddd3c1;
            color: #2b2f37;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .section-band .sub {
            display: block;
            margin-top: 2px;
            font-size: 6px;
            color: #827664;
            font-weight: 400;
            text-transform: none;
            letter-spacing: 0;
        }
        .anexo-table td {
            width: 25%;
            padding: 3px;
            vertical-align: top;
        }
        .anexo-card {
            border: 1px solid #e5dfd4;
            background: #ffffff;
            border-radius: 8px;
            padding: 8px 9px;
        }
        .anexo-card .value {
            margin-top: 3px;
            font-size: 8px;
            font-weight: 700;
            color: #212734;
        }
        .anexo-card .subvalue {
            margin-top: 2px;
            color: #7d786f;
            font-size: 6px;
        }
        .statement {
            margin: 8px 0;
            color: #595850;
            text-align: justify;
            font-size: 6px;
            line-height: 1.4;
        }
        .compact-table th,
        .compact-table td,
        .pricing-table td {
            border-bottom: 1px solid #ece6dc;
            padding: 5px 6px;
            vertical-align: top;
        }
        .compact-table,
        .pricing-table {
            border: 1px solid #ece6dc;
            background: #ffffff;
        }
        .compact-table th,
        .compact-table td,
        .pricing-table td {
            border-right: 1px solid #ece6dc;
        }
        .compact-table th:last-child,
        .compact-table td:last-child,
        .pricing-table td:last-child {
            border-right: 0;
        }
        .compact-table th {
            color: #8f816a;
            font-size: 6px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            text-align: left;
            background: #fcfaf6;
        }
        .pricing-table .price-label {
            width: 68%;
            color: #8f816a;
            font-size: 6px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .pricing-table .grand-total td {
            color: #8b6a2f;
            font-weight: 700;
        }
        .two-col {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .two-col td {
            width: 50%;
            vertical-align: top;
            padding-top: 2px;
            padding-bottom: 2px;
        }
        .scope-card ul {
            margin: 6px 0 0 14px;
            padding: 0;
        }
        .scope-card li {
            margin-bottom: 4px;
        }
        .legal-block {
            margin-top: 10px;
        }
        .legal-section {
            margin-bottom: 8px;
            page-break-inside: avoid;
        }
        .legal-title {
            margin: 0 0 3px;
            font-size: 7px;
            font-weight: 700;
            color: #212734;
        }
        .legal-section p {
            margin: 0 0 4px;
            color: #56544f;
            text-align: justify;
            font-size: 6px;
            line-height: 1.35;
        }
        .account-card {
            border: 1px solid #e5dfd4;
            background: #ffffff;
            border-radius: 6px;
            padding: 8px 9px;
            min-height: 126px;
        }
        .account-name {
            margin-bottom: 5px;
            font-size: 7px;
            font-weight: 700;
            color: #212734;
        }
        .security-box {
            margin-top: 10px;
            border: 1px solid #e5dfd4;
            background: #f9f6ef;
            border-radius: 6px;
            padding: 8px 10px;
        }
        .hash {
            margin-top: 4px;
            font-size: 6px;
            color: #7d786f;
            line-height: 1.25;
            word-wrap: break-word;
        }
        .signature-card {
            border: 1px solid #e5dfd4;
            background: #ffffff;
            border-radius: 6px;
            padding: 10px;
        }
        .signature-role {
            color: #8f816a;
            font-size: 6px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .signature-name {
            margin-top: 4px;
            font-size: 8px;
            font-weight: 700;
            color: #212734;
        }
        .signature-meta {
            margin-top: 2px;
            color: #6d6860;
            font-size: 7px;
        }
        .signature-line {
            margin-top: 22px;
            height: 52px;
            border-bottom: 1px solid #6b665f;
            text-align: center;
        }
        .signature-line img {
            max-width: 150px;
            max-height: 44px;
        }
        .signature-anchor {
            display: block;
            margin-top: 34px;
            font-size: 1px;
            line-height: 1;
            color: #ffffff;
        }
        .signature-caption {
            margin-top: 5px;
            color: #8f816a;
            font-size: 6px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }
        .footer {
            margin-top: 10px;
            padding-top: 6px;
            border-top: 1px solid #e5dfd4;
            text-align: center;
            color: #7d786f;
            font-size: 6px;
        }
        .panel-table {
            border: 1px solid #e5dfd4;
            border-radius: 6px;
            overflow: hidden;
            background: #ffffff;
        }
    </style>
</head>
@php
    $contractCode = $contract->contract_code ?: ('CTR-'.$reservation->id);
    $reservationCode = $reservation->reservation_code ?: ('SKY-'.$reservation->id);
    $documentHash = strtoupper(hash('sha256', implode('|', [
        $contractCode,
        $reservationCode,
        (string) $route,
        (string) $finalPrice,
        (string) optional($contract->generated_at)->timestamp,
    ])));
    $validationCode = strtoupper(substr($documentHash, 0, 12));
    $issueDate = $contractDate ?: now()->format('d/m/Y');
    $reservationDateTime = $departureDate ?: 'Pendiente';
    $originLabel = $segments[0]['origin'] ?? 'Origen por definir';
    $destinationLabel = $segments[count($segments) - 1]['destination'] ?? 'Destino por definir';
    $operatorDisplay = trim((string) $operator) !== '' ? $operator : 'Red Aviation';
    $clientDisplay = trim((string) $customerName) !== '' ? $customerName : 'Cliente por confirmar';
    $clauses = [
        [
            'title' => '1. Definiciones',
            'body' => [
                'Para efectos del presente contrato, el "Prestador del Servicio" sera RED AVIATION COMPANY S.A. DE C.V. y el "Cliente" sera la persona fisica o moral que contrata la coordinacion del servicio aereo privado. El "Servicio" comprende la gestion comercial, operativa y documental de la reserva descrita en este instrumento.',
            ],
        ],
        [
            'title' => '2. Objeto del contrato',
            'body' => [
                'El Prestador coordina y gestiona un servicio de aviacion ejecutiva privada para la ruta '.$route.', con aeronave '.$aircraft.' y configuracion compatible con '.$passengers.'. La operacion final queda sujeta a disponibilidad, slots, permisos, meteorologia y criterios de seguridad operacional.',
            ],
        ],
        [
            'title' => '3. Servicios contratados',
            'body' => [
                'La reserva incluye la coordinacion comercial del vuelo, la asignacion de aeronave y tripulacion conforme al itinerario aprobado, la supervision operativa del servicio y el seguimiento documental necesario para su correcta ejecucion.',
                'Los servicios complementarios no previstos expresamente, asi como ajustes por tiempos de espera, cambios de ruta, permisos especiales, pernoctas, catering extraordinario o transportacion terrestre, se cotizaran por separado cuando resulten aplicables.',
            ],
        ],
        [
            'title' => '4. Costo total, deposito y saldo',
            'body' => [
                'El costo total estimado del servicio asciende a '.$finalPrice.'.',
            ],
        ],
        [
            'title' => '5. Condiciones de pago',
            'body' => [
                'El Cliente realizara los pagos mediante transferencia bancaria a las cuentas autorizadas por el Prestador del Servicio. La confirmacion definitiva del vuelo podra condicionarse a la acreditacion del deposito o del pago total, segun la cercania de la fecha de salida y la politica operativa aplicable.',
            ],
        ],
        [
            'title' => '6. Cambios, cancelaciones y reembolsos',
            'body' => [
                'Cualquier cambio o cancelacion solicitado por el Cliente estara sujeto a disponibilidad, penalidades aplicables, costos ya comprometidos frente a operadores, FBO, permisos, slots y proveedores complementarios. Los reembolsos, en caso de proceder, se limitaran a montos efectivamente recuperables por el Prestador del Servicio.',
            ],
        ],
        [
            'title' => '7. Responsabilidades del Cliente',
            'body' => [
                'El Cliente debera proporcionar con oportunidad los nombres, documentos, datos de pasajeros, requerimientos especiales, pesos estimados de equipaje y cualquier otra informacion necesaria para la planeacion operativa del vuelo. La omision o error en dichos datos podra generar retrasos, costos adicionales o imposibilidad de prestar el servicio.',
            ],
        ],
        [
            'title' => '8. Seguridad operacional y fuerza mayor',
            'body' => [
                'El Prestador del Servicio y el operador podran modificar, posponer o cancelar la operacion cuando existan condiciones meteorologicas adversas, restricciones de autoridad, contingencias de seguridad, fallas tecnicas imprevistas, cierres aeroportuarios o cualquier evento de fuerza mayor que comprometa la seguridad o viabilidad del vuelo.',
            ],
        ],
        [
            'title' => '9. Proteccion de datos y confidencialidad',
            'body' => [
                'La informacion compartida por el Cliente sera tratada con caracter confidencial y utilizada exclusivamente para fines comerciales, operativos, regulatorios y de cumplimiento vinculados al servicio contratado.',
            ],
        ],
        [
            'title' => '10. Validez de firma electronica',
            'body' => [
                'Las partes reconocen la validez juridica de las firmas autografas y/o electronicas incorporadas a este documento, asi como de los registros de generacion, folio unico y huella digital documental asociados al presente contrato.',
            ],
        ],
    ];
@endphp
<body>
    <div class="page">
        <div class="frame">
            <table class="top-nav">
                <tr>
                    <td class="brand">SkyGroup</td>
                    <td class="menu"><span>Reservar</span><span>Mis vuelos</span><span>Perfil</span></td>
                    <td class="user">{{ $clientDisplay }}</td>
                </tr>
            </table>

            <div class="hero-banner">
                <div class="banner-box">
                    @if(is_file($logoPath))
                        <img src="{{ $logoPath }}" alt="Sky Group">
                    @endif
                    <div class="banner-caption">Aircraft maintenance services including airframe, turbines, reciprocating engines, avionics, component repair, and air taxi operations.</div>
                </div>
            </div>

            <div class="hero-content">
                <table class="hero-head">
                    <tr>
                        <td>
                            <div class="reservation-strip">Reserva {{ $reservationCode }}</div>
                        </td>
                        <td class="hero-badge-wrap">
                            <span class="hero-badge">Confidencial · Documento para firma</span>
                        </td>
                    </tr>
                </table>

                <h1 class="title">Contrato de prestacion de servicios de aviacion ejecutiva</h1>
                <div class="route">{{ $route }}</div>
                <div class="hero-metrics">
                    <span>Salida: {{ $reservationDateTime }}</span>
                    <span>Pasajeros: {{ $passengers }}</span>
                    <span>Aeronave: {{ $aircraft }}</span>
                    <span>Tramos: {{ count($segments) }}</span>
                    <span>Total: {{ $finalPrice }}</span>
                </div>
                <p class="intro">
                    El presente instrumento documenta la coordinacion del servicio contratado entre
                    <strong>RED AVIATION COMPANY S.A. DE C.V.</strong> y <strong>{{ $clientDisplay }}</strong>,
                    para la operacion programada con salida <strong>{{ $reservationDateTime }}</strong>.
                </p>
            </div>

            <table class="summary-grid">
            <tr>
                <td class="gutter-right" style="width: 25%;">
                    <div class="card">
                        <div class="label">Cliente</div>
                        <div class="metric">{{ $clientDisplay }}</div>
                        <div class="hint">{{ $customerRepresentative }}</div>
                    </div>
                </td>
                <td class="gutter-right" style="width: 25%;">
                    <div class="card">
                        <div class="label">Ruta</div>
                        <div class="metric">{{ $route }}</div>
                        <div class="hint">{{ count($segments) }} tramo(s)</div>
                    </div>
                </td>
                <td class="gutter-right" style="width: 25%;">
                    <div class="card">
                        <div class="label">Aeronave</div>
                        <div class="metric">{{ $aircraft }}</div>
                        <div class="hint">{{ $aircraftCategory }}</div>
                    </div>
                </td>
                <td class="gutter-left" style="width: 25%;">
                    <div class="card">
                        <div class="label">Tarifa total</div>
                        <div class="metric">{{ $finalPrice }}</div>
                        <div class="hint">Deposito {{ $depositText }}</div>
                    </div>
                </td>
            </tr>
            </table>

            <div class="section-band">
            ANEXO A — DATOS COMERCIALES DE LA RESERVA
            <span class="sub">{{ $issueDate }}</span>
            </div>

            <table class="anexo-table">
            <tr>
                <td>
                    <div class="anexo-card">
                        <div class="label">Ruta contratada</div>
                        <div class="value">{{ $route }}</div>
                    </div>
                </td>
                <td>
                    <div class="anexo-card">
                        <div class="label">Aeronave</div>
                        <div class="value">{{ $aircraft }}</div>
                        <div class="subvalue">{{ $aircraftCategory }}</div>
                    </div>
                </td>
                <td>
                    <div class="anexo-card">
                        <div class="label">Fecha de salida</div>
                        <div class="value">{{ $reservationDateTime }}</div>
                    </div>
                </td>
                <td>
                    <div class="anexo-card">
                        <div class="label">Tarifa final</div>
                        <div class="value">{{ $finalPrice }}</div>
                    </div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="anexo-card">
                        <div class="label">Cliente</div>
                        <div class="value">{{ $clientDisplay }}</div>
                    </div>
                </td>
                <td>
                    <div class="anexo-card">
                        <div class="label">Servicio</div>
                        <div class="value">{{ $serviceTier }}</div>
                    </div>
                </td>
                <td>
                    <div class="anexo-card">
                        <div class="label">Pasajeros</div>
                        <div class="value">{{ $passengers }}</div>
                    </div>
                </td>
                <td>
                    <div class="anexo-card">
                        <div class="label">Operador</div>
                        <div class="value">{{ $operatorDisplay }}</div>
                    </div>
                </td>
            </tr>
            </table>

            <p class="statement">
            El presente contrato se celebra con fecha {{ $issueDate }} entre <strong>RED AVIATION COMPANY S.A. DE C.V.</strong>,
            en adelante el Prestador del Servicio, y <strong>{{ $clientDisplay }}</strong>, en adelante el Cliente, conforme a los
            terminos y condiciones comerciales y operativas aqui descritas. La informacion contenida en este documento forma parte integral
            del expediente contractual y de la reserva identificada con el folio <strong>{{ $reservationCode }}</strong>.
            </p>

            <div class="section-title">Resumen comercial</div>
            <div class="section-rule"></div>
            <table class="compact-table panel-table">
            <thead>
                <tr>
                    <th>Operador</th>
                    <th>Cliente</th>
                    <th>Ruta</th>
                    <th>Salida</th>
                    <th>Aeronave</th>
                    <th>Pasajeros</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $operatorDisplay }}</td>
                    <td>{{ $clientDisplay }}</td>
                    <td>{{ $route }}</td>
                    <td>{{ $reservationDateTime }}</td>
                    <td>{{ $aircraft }}</td>
                    <td>{{ $passengers }}</td>
                </tr>
            </tbody>
            </table>

            <div class="section-title">Itinerario</div>
            <div class="section-rule"></div>
            <table class="compact-table panel-table">
            <thead>
                <tr>
                    <th>Tramo</th>
                    <th>Origen</th>
                    <th>Destino</th>
                    <th>Fecha</th>
                    <th>Hora</th>
                </tr>
            </thead>
            <tbody>
                @forelse($segments as $segment)
                    @php
                        $segmentDeparture = (string) ($segment['departure'] ?? '');
                        $segmentDate = $segmentDeparture !== '' && str_contains($segmentDeparture, ' ')
                            ? explode(' ', $segmentDeparture)[0]
                            : ($segmentDeparture !== '' ? $segmentDeparture : 'Por confirmar');
                        $segmentTime = $segmentDeparture !== '' && str_contains($segmentDeparture, ' ')
                            ? explode(' ', $segmentDeparture, 2)[1]
                            : 'Por confirmar';
                    @endphp
                    <tr>
                        <td>{{ $segment['order'] }}</td>
                        <td>{{ $segment['origin'] }}</td>
                        <td>{{ $segment['destination'] }}</td>
                        <td>{{ $segmentDate }}</td>
                        <td>{{ $segmentTime }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">Sin tramos registrados.</td>
                    </tr>
                @endforelse
            </tbody>
            </table>

            <div class="section-title">Desglose economico</div>
            <div class="section-rule"></div>
            <table class="pricing-table panel-table">
            <tbody>
                @foreach($pricingRows as $row)
                    <tr class="{{ str_contains(strtolower($row['label']), 'total') ? 'grand-total' : '' }}">
                        <td class="price-label">{{ $row['label'] }}</td>
                        <td>{{ '$'.number_format($row['amount'], 2, '.', ',').' USD' }}</td>
                    </tr>
                @endforeach
            </tbody>
            </table>

            <div class="section-title">Incluye y no incluye</div>
            <div class="section-rule"></div>
            <table class="two-col">
            <tr>
                <td class="gutter-right">
                    <div class="card scope-card">
                        <div class="label">Incluye</div>
                        <ul>
                            @foreach($includesItems as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                </td>
                <td class="gutter-left">
                    <div class="card scope-card">
                        <div class="label">No incluye, salvo pacto expreso</div>
                        <ul>
                            @foreach($excludesItems as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </div>
                </td>
            </tr>
            </table>

            <div class="legal-block">
            @foreach($clauses as $clause)
                <div class="legal-section">
                    <div class="legal-title">{{ $clause['title'] }}</div>
                    @foreach($clause['body'] as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>
            @endforeach

            @if(!empty($conditions))
                <div class="legal-section">
                    <div class="legal-title">11. Notas operativas complementarias</div>
                    @foreach($conditions as $condition)
                        <p>{{ $condition }}</p>
                    @endforeach
                </div>
            @endif
            </div>

            <div class="section-title">Cuentas recaudadoras autorizadas</div>
            <div class="section-rule"></div>
            <table class="accounts-table">
            <tr>
                @foreach($bankAccounts as $index => $account)
                    <td style="width: 33.33%;{{ $index < 2 ? 'padding-right: 4px;' : 'padding-left: 4px;' }}">
                        <div class="account-card">
                            <div class="account-name">{{ $account['bank'] }}</div>
                            <div><span class="label">Beneficiario</span><br>{{ $account['beneficiary'] }}</div>
                            <div style="margin-top: 4px;"><span class="label">Cuenta</span><br>{{ $account['account'] }}</div>
                            <div style="margin-top: 4px;"><span class="label">Clabe</span><br>{{ $account['clabe'] }}</div>
                            <div style="margin-top: 4px;"><span class="label">RFC</span><br>{{ $account['rfc'] }}</div>
                        </div>
                    </td>
                @endforeach
            </tr>
            </table>

            <div class="security-box">
            <div class="label">Seguridad documental</div>
            <div style="margin-top: 4px;"><strong>Numero de contrato:</strong> {{ $contractCode }}</div>
            <div style="margin-top: 2px;"><strong>Codigo de validacion:</strong> {{ $validationCode }}</div>
            <div style="margin-top: 2px;"><strong>Hash SHA256:</strong></div>
            <div class="hash">{{ $documentHash }}</div>
            <div style="margin-top: 4px; color: #69645d;">Este documento fue generado electronicamente y puede validarse mediante su folio unico y huella digital documental.</div>
            </div>

            <div class="section-title">Firmas</div>
            <div class="section-rule"></div>
            <table class="signature-table signature-table-single">
            <tr>
                <td>
                    <div class="signature-card">
                        <div class="signature-role">Cliente</div>
                        <div class="signature-name">{{ $clientDisplay }}</div>
                        <div class="signature-meta">{{ $customerRepresentative }}</div>
                        <div class="signature-line">
                            @if($clientSignatureDataUrl)
                                <img src="{{ $clientSignatureDataUrl }}" alt="Firma cliente">
                            @endif
                            <span class="signature-anchor">/sig_cliente/</span>
                        </div>
                        <div class="signature-caption">Firma digital / electronica</div>
                        <div style="margin-top: 6px; color: #6d6860;">
                            <strong>Folio:</strong> {{ $contractCode }}<br>
                            <strong>Ruta:</strong> {{ $route }}<br>
                            <strong>Total:</strong> {{ $finalPrice }}
                        </div>
                    </div>
                </td>
            </tr>
            </table>

            <div class="footer">
                sales@redskyg.com · https://redskyg.com/mx · +52 558 618 6576 · +52 722 112 6671 · +1 305 464 6394
            </div>
        </div>
    </div>
</body>
</html>
