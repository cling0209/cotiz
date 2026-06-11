<?php

namespace App\Services;

use App\Models\Nota;
use Illuminate\Support\Facades\DB;

class NotaService
{
    public function crear(string $usuario, ?string $descripcion = null, ?int $notaOrigen = null, ?string $sistema = null): Nota
    {
        return DB::transaction(function () use ($usuario, $descripcion, $notaOrigen, $sistema) {
            $nronota = $this->siguienteNronota();
            $notaSoftland = $this->siguienteNotaSoftland();

            $nota = Nota::create([
                'nronota' => $nronota,
                'descripcion' => $descripcion ?? 'Cotización '.$nronota,
                'fecha' => now()->toDateString(),
                'usuario' => $usuario,
                'encargado' => '',
                'empresa' => '',
                'celular' => '',
                'contacto' => '',
                'contactocorreo' => '',
                'nota_softland' => $notaSoftland,
                'notaorigen' => $notaOrigen ?? 0,
                'sistema' => $sistema ?? config('app.name'),
                'enviadoapi' => 0,
                'diashabiles' => 2,
                'factor_precio_venta' => config('cotiz.factor_precio_venta'),
            ]);

            return $nota;
        });
    }

    public function obtenerUltima(string $usuario): ?Nota
    {
        return Nota::query()
            ->where('usuario', $usuario)
            ->orderByDesc('nronota')
            ->first();
    }

    public function modificarCabecera(Nota $nota, array $datos): Nota
    {
        $factor = isset($datos['factor_precio_venta']) && (float) $datos['factor_precio_venta'] > 0
            ? (float) $datos['factor_precio_venta']
            : $nota->factor_precio_venta;

        $nota->update([
            'descripcion' => $datos['descripcion'] ?? $nota->descripcion,
            'empresa' => $datos['empresa'] ?? $nota->empresa,
            'encargado' => $datos['encargado'] ?? $nota->encargado,
            'celular' => $datos['celular'] ?? $nota->celular,
            'contacto' => $datos['contacto'] ?? $nota->contacto,
            'contactocorreo' => $datos['contactocorreo'] ?? $nota->contactocorreo,
            'rutempresa' => $datos['rutempresa'] ?? $nota->rutempresa,
            'diashabiles' => (int) ($datos['diashabiles'] ?? $nota->diashabiles ?? 2),
            'ocompra' => $datos['ocompra'] ?? $nota->ocompra,
            'fechaentrega' => $datos['fechaentrega'] ?? $nota->fechaentrega,
            'factor_precio_venta' => $factor,
        ]);

        return $nota->fresh();
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

    private function siguienteNotaSoftland(): int
    {
        $max = Nota::query()->where('nota_softland', '>', 0)->max('nota_softland');

        return $max ? ((int) $max + 1) : 10000;
    }
}
