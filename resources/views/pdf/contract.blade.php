<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $contract->contract_code ?: 'Contrato de reserva' }}</title>
    <style>
        @page { margin: 20px 24px 28px; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #1a1a1a;
            font-size: 12px;
            line-height: 1.45;
            background: #ffffff;
        }
        .sheet { border: 1px solid #ddd4c6; border-radius: 18px; overflow: hidden; }
        .brandbar { padding: 18px 24px; background: #17212b; text-align: center; }
        .brandbar img { width: 220px; }
        .body { padding: 24px; background: #fffdf9; }
        .eyebrow { color: #8b6a24; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
        .badge { float: right; padding: 6px 10px; border-radius: 999px; background: #f7ebcc; color: #8b6a24; font-size: 10px; font-weight: 700; }
        h1 { margin: 10px 0 8px; font-size: 28px; line-height: 1.05; }
        .route { margin: 0 0 10px; font-size: 18px; color: #3c3328; font-weight: 700; }
        .meta span { display: inline-block; margin: 0 16px 8px 0; color: #625d55; font-weight: 700; }
        .section-title { margin: 26px 0 10px; font-size: 16px; text-transform: uppercase; letter-spacing: .04em; }
        .summary { width: 100%; border-collapse: collapse; margin: 14px 0 0; }
        .summary th, .summary td { border: 1px solid #e9e2d4; padding: 10px 12px; vertical-align: top; }
        .summary th { width: 18%; background: #f4eee3; color: #625d55; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; text-align: left; }
        .summary td { background: #faf8f3; font-weight: 700; }
        .cards { margin-top: 14px; }
        .card { width: 48%; display: inline-block; vertical-align: top; padding: 12px; border: 1px solid #e9e2d4; border-radius: 12px; background: #faf8f3; margin-right: 2%; }
        .card:last-child { margin-right: 0; }
        .card ul { margin: 8px 0 0 16px; padding: 0; }
        .card li { margin-bottom: 6px; }
        .pricing-total { color: #8b6a24; font-weight: 700; }
        .block { margin-top: 18px; page-break-inside: avoid; }
        .block h3 { margin: 0 0 8px; font-size: 14px; }
        .block p { margin: 0 0 8px; }
        .block ul { margin: 0; padding-left: 18px; }
        .block li { margin-bottom: 6px; }
        .accounts .account { width: 31.5%; display: inline-block; vertical-align: top; margin-right: 2%; padding: 10px 12px; border: 1px solid #e9e2d4; border-radius: 12px; background: #faf8f3; }
        .accounts .account:last-child { margin-right: 0; }
        .accounts .account strong, .signatures .sign strong { display: block; margin-bottom: 4px; }
        .signatures .sign { width: 48%; display: inline-block; vertical-align: top; margin-right: 2%; padding: 12px; border: 1px solid #e9e2d4; border-radius: 12px; background: #faf8f3; }
        .signatures .sign:last-child { margin-right: 0; }
        .line { margin-top: 18px; padding-top: 8px; border-top: 1px solid #1a1a1a; min-height: 58px; }
        .line img { max-width: 180px; max-height: 52px; }
        .footer { margin-top: 22px; padding-top: 12px; border-top: 2px solid #2f2f2f; color: #4f4a43; font-size: 10px; }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="brandbar">
            @if(is_file($logoPath))
                <img src="{{ $logoPath }}" alt="Sky Group">
            @endif
        </div>

        <div class="body">
            <span class="eyebrow">Reserva {{ $reservation->reservation_code ?: 'SKY-'.$reservation->id }}</span>
            <span class="badge">CONFIDENCIAL · DOCUMENTO PARA FIRMA</span>
            <h1>Contrato de prestación de servicios de aviación ejecutiva</h1>
            <p class="route">{{ $route }}</p>
            <div class="meta">
                <span>Fecha de salida: {{ $departureDate }}</span>
                <span>Pasajeros: {{ $passengers }}</span>
                <span>Aeronave: {{ $aircraft }}</span>
                <span>Tramos: {{ count($segments) }}</span>
                <span>Total: {{ $finalPrice }}</span>
            </div>

            <div class="block">
                <h3 class="section-title">Anexo A — Resumen comercial</h3>
                <table class="summary">
                    <tbody>
                        <tr><th>Reserva</th><td>{{ $reservation->reservation_code ?: 'N/A' }}</td><th>Cliente</th><td>{{ $customerName }}</td></tr>
                        <tr><th>Operador</th><td>{{ $operator }}</td><th>Ruta</th><td>{{ $route }}</td></tr>
                        <tr><th>Salida</th><td>{{ $departureDate }}</td><th>Aeronave</th><td>{{ $aircraft }}</td></tr>
                        <tr><th>Cabina</th><td>{{ $aircraftCategory }}</td><th>Pasajeros</th><td>{{ $passengers }}</td></tr>
                        <tr><th>Servicio</th><td>{{ $serviceTier }}</td><th>Costo total</th><td>{{ $finalPrice }}</td></tr>
                        <tr><th>Depósito</th><td>{{ $depositText }}</td><th>Saldo</th><td>{{ $balanceText }}</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="block">
                <h3>Itinerario</h3>
                <table class="summary">
                    <thead>
                        <tr><th>Tramo</th><th>Origen</th><th>Destino</th><th>Salida</th></tr>
                    </thead>
                    <tbody>
                        @foreach($segments as $segment)
                            <tr>
                                <td>{{ $segment['order'] }}</td>
                                <td>{{ $segment['origin'] }}</td>
                                <td>{{ $segment['destination'] }}</td>
                                <td>{{ $segment['departure'] ?: 'Por confirmar' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="block">
                <h3>Desglose comercial</h3>
                <table class="summary">
                    <tbody>
                        @foreach($pricingRows as $row)
                            <tr>
                                <th>{{ $row['label'] }}</th>
                                <td class="{{ str_contains(strtolower($row['label']), 'total') ? 'pricing-total' : '' }}">{{ '$'.number_format($row['amount'], 2, '.', ',').' USD' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="cards">
                <div class="card">
                    <strong>Incluye</strong>
                    <ul>
                        @foreach($includesItems as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
                <div class="card">
                    <strong>No incluye, salvo pacto expreso</strong>
                    <ul>
                        @foreach($excludesItems as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="block">
                <h3>Contrato</h3>
                <p>El presente Contrato se celebra en fecha {{ $contractDate }} entre <strong>RED AVIATION COMPANY S.A. DE C.V.</strong> y <strong>{{ $customerName }}</strong>, representado por <strong>{{ $customerRepresentative }}</strong>, con domicilio en <strong>{{ $customerAddress }}</strong>.</p>
                <p>El servicio contratado corresponde a la ruta <strong>{{ $route }}</strong>, con salida programada para <strong>{{ $departureDate }}</strong>, aeronave <strong>{{ $aircraft }}</strong>, categoría <strong>{{ $aircraftCategory }}</strong> y <strong>{{ $passengers }}</strong>.</p>
                <p>El costo total del servicio asciende a <strong>{{ $finalPrice }}</strong>. El depósito requerido es <strong>{{ $depositText }}</strong> y el saldo restante estimado es <strong>{{ $balanceText }}</strong>.</p>
            </div>

            @if(!empty($conditions))
                <div class="block">
                    <h3>Condiciones operativas</h3>
                    <ul>
                        @foreach($conditions as $condition)
                            <li>{{ $condition }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="block">
                <h3>Cuentas para pago</h3>
                <div class="accounts">
                    @foreach($bankAccounts as $account)
                        <div class="account">
                            <strong>{{ $account['bank'] }}</strong>
                            <div>Cuenta: {{ $account['account'] }}</div>
                            <div>CLABE: {{ $account['clabe'] }}</div>
                            <div>Beneficiario: {{ $account['beneficiary'] }}</div>
                            <div>RFC: {{ $account['rfc'] }}</div>
                        </div>
                    @endforeach
                </div>
                <p style="margin-top:10px;">El Cliente reconoce y acepta que, por razones administrativas, fiscales, operativas o de cobranza, los pagos podrán realizarse a cuentas bancarias de terceros autorizados expresamente por el Prestador del Servicio.</p>
            </div>

            <div class="block signatures">
                <h3>Firmas</h3>
                <div class="sign">
                    <strong>Prestador del Servicio</strong>
                    <div>RED AVIATION COMPANY S.A. DE C.V.</div>
                    <div>José Luis Hernández Ortiz</div>
                    <div class="line">
                        @if(is_file($providerSignaturePath))
                            <img src="{{ $providerSignaturePath }}" alt="Firma proveedor">
                        @endif
                    </div>
                </div>
                <div class="sign">
                    <strong>Cliente</strong>
                    <div>{{ $customerName }}</div>
                    <div>{{ $customerRepresentative }}</div>
                    <div class="line">
                        @if($clientSignatureDataUrl)
                            <img src="{{ $clientSignatureDataUrl }}" alt="Firma cliente">
                        @endif
                    </div>
                </div>
            </div>

            <div class="footer">
                <div>Teléfonos: +52 558 618 6576 · +52 722 112 6671 · +1 305 464 6394</div>
                <div>Correo: sales@redskyg.com · Sitio: https://redskyg.com/mx</div>
            </div>
        </div>
    </div>
</body>
</html>
