<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cotización {{ $nota->nronota }}</title>
    <style>
        @page { margin: 8mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; margin: 0; padding: 0; }
        .header { width: 100%; margin-bottom: 10px; }
        .header td { vertical-align: top; }
        .empresa { font-size: 8px; line-height: 1.35; }
        .empresa .nombre { font-weight: bold; font-size: 9px; }
        .banner { text-align: center; color: #a80000; font-weight: bold; font-size: 8px; margin: 8px 0 10px; }
        .meta { border-collapse: collapse; float: right; font-size: 8px; }
        .meta td { border: 1px solid #333; padding: 2px 5px; }
        .cliente { border-collapse: collapse; width: 100%; margin-bottom: 8px; font-size: 8px; }
        .cliente td, .cliente th { border: 1px solid #333; padding: 3px 5px; }
        .cliente th { text-align: left; width: 80px; background: #f5f5f5; }
        table.detalle { border-collapse: collapse; width: 100%; font-size: 7px; }
        table.detalle th, table.detalle td { border: 1px solid #333; padding: 2px 3px; vertical-align: top; }
        table.detalle th { background: #eee; text-align: center; }
        .img-cell { width: 56px; height: 48px; text-align: center; vertical-align: middle; }
        .img-cell img { max-width: 52px; max-height: 44px; object-fit: contain; }
        .obs-cell { width: 18%; font-size: 7px; text-align: left; word-wrap: break-word; }
        .num { text-align: right; white-space: nowrap; }
        .totales { width: 100%; margin-top: 6px; font-size: 8px; }
        .totales td { padding: 2px 4px; }
        .totales .label { text-align: right; font-weight: bold; width: 78%; }
        .totales .box { border: 1px solid #333; text-align: right; width: 14%; }
        .condiciones { margin-top: 10px; font-size: 8px; line-height: 1.5; }
        .condiciones h4 { margin: 0 0 4px; font-size: 8px; text-decoration: underline; }
    </style>
</head>
<body>
    <table class="header" cellpadding="0" cellspacing="0">
        <tr>
            <td style="width: 38%;">
                <div class="empresa">
                    <div class="nombre">{{ config('cotiz.empresa_nombre') }}</div>
                    <div>{{ config('cotiz.empresa_rut') }}</div>
                    <div>Comercio al por mayor y menor</div>
                    <div>{{ config('cotiz.empresa_direccion') }}</div>
                    <div>{{ config('cotiz.empresa_fono') }}</div>
                    <div>{{ config('cotiz.empresa_correo') }}</div>
                    <div>{{ config('cotiz.empresa_cuenta') }}</div>
                </div>
            </td>
            <td style="width: 24%;"></td>
            <td style="width: 38%;">
                <table class="meta" align="right">
                    <tr><td>Cotización Nro:</td><td>{{ $nota->nronota }}</td></tr>
                    <tr><td>Fecha</td><td>{{ $fecha }}</td></tr>
                    <tr><td>Vigencia</td><td>10 DIAS</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="banner">ENVIO A TRAVES DE CORREOS DE CHILE A REGIONES</div>

    <table class="cliente">
        <tr>
            <th>Cliente</th>
            <td>{{ \Illuminate\Support\Str::limit($nota->empresa ?? '', 40) }}</td>
            <th>Encargado</th>
            <td>{{ \Illuminate\Support\Str::limit($nota->encargado ?? '', 20) }}</td>
            <th>Celular</th>
            <td>{{ $nota->celular }}</td>
        </tr>
        <tr>
            <th>Contacto</th>
            <td>{{ \Illuminate\Support\Str::limit($nota->contacto ?? '', 20) }}</td>
            <th>E-mail</th>
            <td colspan="3">{{ $nota->contactocorreo }}</td>
        </tr>
        <tr>
            <th>email</th>
            <td>{{ config('cotiz.empresa_correo') }}</td>
            <th>Vendedor</th>
            <td colspan="3">{{ $vendedor }}</td>
        </tr>
    </table>

    <table class="detalle">
        <thead>
            <tr>
                <th style="width:22px">ITEM</th>
                <th style="width:52px">IMAGEN REF.</th>
                <th>DESCRIPCION</th>
                <th class="obs-cell">OBSERVACION</th>
                <th style="width:34px">UNIDAD</th>
                <th style="width:32px">CANT.</th>
                <th style="width:48px">PRECIO UNIT. NETO</th>
                <th style="width:48px">SUBTOTAL NETO</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lineas as $idx => $row)
                @php $linea = $row['linea']; @endphp
                <tr>
                    <td class="num">{{ $idx + 1 }}</td>
                    <td class="img-cell">
                        @if($row['image_url'])
                            <img src="{{ $row['image_url'] }}" alt="">
                        @endif
                    </td>
                    <td>{{ $row['prod_nombre'] }}</td>
                    <td class="obs-cell">{{ trim((string) ($linea->observacion_cliente ?? '')) }}</td>
                    <td style="text-align:center">UNIDAD</td>
                    <td class="num">{{ $linea->cantidad }}</td>
                    <td class="num">${{ number_format($linea->prod_valor, 0, '', '.') }}</td>
                    <td class="num">${{ number_format($row['total'], 0, '', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totales" cellpadding="0" cellspacing="0">
        <tr>
            <td class="label">SUB TOTAL NETO</td>
            <td class="box">${{ number_format($totalNeto, 0, '', '.') }}</td>
        </tr>
        <tr>
            <td class="label">IVA 19%</td>
            <td class="box">${{ number_format($iva, 0, '', '.') }}</td>
        </tr>
        <tr>
            <td class="label">TOTAL</td>
            <td class="box">${{ number_format($total, 0, '', '.') }}</td>
        </tr>
    </table>

    <div class="condiciones">
        <h4>Condiciones de Venta:</h4>
        <div><strong>Tiempo de Entrega:</strong> {{ $nota->diashabiles ?? 2 }} días hábiles, una vez recibida la orden de compra</div>
        <div><strong>Términos de Pago:</strong> Según lo estipulado en el portal.</div>
        <div><strong>Precio:</strong> Precio de venta en pesos chilenos.</div>
        <div><strong>Despacho:</strong> Incluido. Según solicitud del cliente.</div>
    </div>
</body>
</html>
