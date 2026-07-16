<?php

namespace App\Services;

use App\Enums\VinculoOrigen;
use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NotaRecepcionApiService
{
    public function __construct(
        protected NotaDetalleService $detalleService,
    ) {}

    /**
     * Equivalente legacy apinota.php → graba_resumen.
     *
     * @return int nronota asignado en destino
     */
    public function grabaResumen(array $payload): int
    {
        $usuario = trim((string) ($payload['usuario'] ?? ''));
        if ($usuario === '') {
            throw new RuntimeException('no viene usuario');
        }

        if (! User::query()->where('username', $usuario)->exists()) {
            throw new RuntimeException('Usuario no existe: '.$usuario);
        }

        $encargado = trim((string) ($payload['encargado'] ?? ''));
        if ($encargado !== '') {
            $duplicada = Nota::query()
                ->whereRaw('trim(encargado) ilike ?', [$encargado])
                ->exists();
            if ($duplicada) {
                throw new RuntimeException('La cotización ya existe en notas: '.$encargado);
            }
        }

        return DB::transaction(function () use ($payload, $usuario) {
            $nronota = $this->siguienteNronota();
            $notaSoftland = (int) ($payload['nota_softland'] ?? 0);
            if ($notaSoftland <= 0) {
                $max = Nota::query()->where('nota_softland', '>', 0)->max('nota_softland');
                $notaSoftland = $max ? ((int) $max + 1) : 10000;
            }

            $fecha = $this->parseFecha($payload['fecha'] ?? null) ?? now()->toDateString();
            $fechaEntrega = $this->parseFecha($payload['fechaentrega'] ?? null);

            Nota::query()->create([
                'nronota' => $nronota,
                'descripcion' => trim((string) ($payload['descripcion'] ?? 'Cotización '.$nronota)),
                'fecha' => $fecha,
                'usuario' => $usuario,
                'empresa' => trim((string) ($payload['empresa'] ?? '')),
                'encargado' => trim((string) ($payload['encargado'] ?? '')),
                'celular' => trim((string) ($payload['celular'] ?? '')),
                'contacto' => trim((string) ($payload['contacto'] ?? '')),
                'contactocorreo' => trim((string) ($payload['contactocorreo'] ?? '')),
                'rutempresa' => trim((string) ($payload['rutempresa'] ?? '')) ?: null,
                'nota_softland' => $notaSoftland,
                'diashabiles' => (int) ($payload['diashabiles'] ?? 0),
                'notaorigen' => (int) ($payload['notaorigen'] ?? 0),
                'sistema' => trim((string) ($payload['sistema'] ?? config('app.name'))),
                'enviadoapi' => (int) ($payload['enviadoapi'] ?? 0),
                'estado' => trim((string) ($payload['estado'] ?? '')) ?: null,
                'estadofecha' => now(),
                'estadousuario' => trim((string) ($payload['estadousuario'] ?? $usuario)),
                'ocompra' => trim((string) ($payload['ocompra'] ?? '')),
                'fechaentrega' => $fechaEntrega,
                'factor_precio_venta' => $this->parseFactor($payload['factor_precio_venta'] ?? null),
            ]);

            return $nronota;
        });
    }

    /**
     * Equivalente legacy apinota.php → graba_detalle.
     */
    public function grabaDetalle(array $payload): void
    {
        $nronota = (int) ($payload['nronota'] ?? 0);
        $prodItem = trim((string) ($payload['prod_item'] ?? ''));

        if ($nronota <= 0) {
            throw new RuntimeException('nronota inválido');
        }

        if ($prodItem === '') {
            throw new RuntimeException('prod_item inválido');
        }

        if (! Nota::query()->where('nronota', $nronota)->exists()) {
            throw new RuntimeException('La nota no existe: '.$nronota);
        }

        DB::transaction(function () use ($payload, $nronota, $prodItem) {
            if (! Maeprod::query()->where('prod_item', $prodItem)->exists()) {
                Maeprod::query()->create([
                    'prod_item' => $prodItem,
                    'prod_nombre' => trim((string) ($payload['prod_nombre'] ?? $prodItem)),
                    'prod_imagen' => trim((string) ($payload['prod_imagen'] ?? '')),
                    'prod_valor' => (int) ($payload['prod_valor'] ?? 0),
                    'prod_stock_real' => null,
                    'prod_gramaje' => trim((string) ($payload['prod_gramaje'] ?? '')),
                    'prod_familia' => trim((string) ($payload['prod_familia'] ?? '')),
                    'prod_item_softland' => trim((string) ($payload['prod_item_softland'] ?? '')),
                    'prod_valor_fecha' => now(),
                    'prod_valor_costo' => (int) ($payload['prod_valor_costo'] ?? 0),
                    'prod_user_upd' => trim((string) ($payload['prod_user_upd'] ?? '')),
                ]);
            }

            $orden = (int) ($payload['orden'] ?? 0);
            if ($orden <= 0) {
                $orden = (int) NotaDetalle::query()
                    ->where('nronota', $nronota)
                    ->max('orden') + 1;
            }

            $descAgile = trim((string) ($payload['prod_descripcion_agile'] ?? '')) ?: null;

            $linea = NotaDetalle::query()->updateOrCreate(
                [
                    'nronota' => $nronota,
                    'prod_item' => $prodItem,
                    'orden' => $orden,
                ],
                [
                    'prod_valor' => (int) ($payload['prod_valor'] ?? 0),
                    'cantidad' => max(1, (int) ($payload['cantidad'] ?? 1)),
                    'fechahora' => now(),
                    'prod_valor_costo' => (int) ($payload['prod_valor_costo'] ?? 0),
                    'prod_item_agile' => trim((string) ($payload['prod_item_agile'] ?? '')) ?: null,
                    'prod_descripcion_agile' => $descAgile,
                    'prod_descripcion_maestro' => $descAgile,
                ],
            );

            $usuarioApi = trim((string) ($payload['prod_user_upd'] ?? ''));
            $this->detalleService->sincronizarVinculoAgileMaeprod(
                $linea,
                $usuarioApi !== '' ? $usuarioApi : null,
                VinculoOrigen::API,
            );
        });
    }

    /**
     * Equivalente legacy apiconsulta.php → acción cotizacion.
     */
    public function consultarPorEncargado(string $encargado): int
    {
        $codigo = trim($encargado);
        if ($codigo === '') {
            throw new RuntimeException('encargado inválido');
        }

        $nota = Nota::query()
            ->whereRaw('lower(trim(encargado)) = lower(?)', [$codigo])
            ->first(['nronota']);

        if (! $nota) {
            throw new RuntimeException('La cotización no existe en notas.');
        }

        return (int) $nota->nronota;
    }

    private function parseFecha(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }

        if (preg_match('/^\d{8}$/', $texto)) {
            return substr($texto, 0, 4).'-'.substr($texto, 4, 2).'-'.substr($texto, 6, 2);
        }

        return $texto;
    }

    private function parseFactor(mixed $valor): float
    {
        if ($valor === null || $valor === '') {
            return (float) config('cotiz.factor_precio_venta', 1.22);
        }

        $texto = trim(str_replace(',', '.', (string) $valor));
        $factor = (float) $texto;

        return $factor > 0 ? round($factor, 4) : (float) config('cotiz.factor_precio_venta', 1.22);
    }

    private function siguienteNronota(): int
    {
        $row = DB::table('nronota_seq')->lockForUpdate()->first();
        if (! $row) {
            DB::table('nronota_seq')->insert(['ultimo' => 1]);

            return 1;
        }

        $next = ((int) $row->ultimo) + 1;
        DB::table('nronota_seq')->update(['ultimo' => $next]);

        return $next;
    }
}
