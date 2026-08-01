<?php

namespace App\Services;

use App\Enums\NotaAuditoriaAccion;
use App\Models\Nota;
use App\Models\NotaAuditoria;

class NotaAuditoriaService
{
    public function registrar(
        Nota|int $nota,
        ?string $usuario,
        NotaAuditoriaAccion $accion,
        ?string $observacion = null,
    ): ?NotaAuditoria {
        $usuario = mb_substr(trim((string) $usuario), 0, 20);
        if ($usuario === '') {
            return null;
        }

        $nronota = $nota instanceof Nota ? (int) $nota->nronota : (int) $nota;
        if ($nronota <= 0) {
            return null;
        }

        $obs = trim((string) $observacion);
        if ($obs !== '') {
            $obs = mb_substr($obs, 0, 500);
        } else {
            $obs = null;
        }

        return NotaAuditoria::query()->create([
            'nronota' => $nronota,
            'usuario' => $usuario,
            'fechahora' => now(),
            'accion' => $accion,
            'observacion' => $obs,
        ]);
    }

    public function registrarAgregar(Nota|int $nota, ?string $usuario, ?string $observacion = null): ?NotaAuditoria
    {
        return $this->registrar(
            $nota,
            $usuario,
            NotaAuditoriaAccion::AGREGAR,
            $observacion ?? 'Alta de cotización',
        );
    }

    public function registrarModificar(Nota|int $nota, ?string $usuario, ?string $observacion = null): ?NotaAuditoria
    {
        return $this->registrar(
            $nota,
            $usuario,
            NotaAuditoriaAccion::MODIFICAR,
            $observacion ?? 'Modificación de cotización',
        );
    }

    public function registrarPdf(Nota|int $nota, ?string $usuario, ?string $observacion = null): ?NotaAuditoria
    {
        return $this->registrar(
            $nota,
            $usuario,
            NotaAuditoriaAccion::PDF,
            $observacion ?? 'Generación de PDF',
        );
    }
}
