<?php

namespace App\Services;

use App\Jobs\ProcessOportunidadBusquedaJob;
use App\Models\OportunidadBusquedaCorrida;
use Illuminate\Support\Carbon;
use RuntimeException;

class OportunidadBusquedaService
{
    public const ESTADO_RUNNING = 'running';

    public const ESTADO_COMPLETED = 'completed';

    public const ESTADO_CANCELLED = 'cancelled';

    public const ESTADO_ERROR = 'error';

    public function __construct(
        protected OportunidadParaCotizarService $oportunidades,
    ) {}

    public function habilitada(): bool
    {
        return (bool) config('cotiz.mercadopublico.analisis_admin_habilitado', false);
    }

    public function corridaEnCurso(): ?OportunidadBusquedaCorrida
    {
        return OportunidadBusquedaCorrida::query()
            ->where('estado', self::ESTADO_RUNNING)
            ->latest('id')
            ->first();
    }

    public function ultimaCorrida(): ?OportunidadBusquedaCorrida
    {
        return OportunidadBusquedaCorrida::query()->latest('id')->first();
    }

    public function iniciar(string $usuario = 'sistema'): OportunidadBusquedaCorrida
    {
        if (! $this->habilitada()) {
            throw new RuntimeException('La búsqueda automática de oportunidades no está habilitada en este sitio.');
        }

        if (! $this->oportunidades->apiConfigurada()) {
            throw new RuntimeException('API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.');
        }

        if (config('queue.default') === 'sync' && app()->isProduction()) {
            throw new RuntimeException(
                'La búsqueda en segundo plano requiere QUEUE_CONNECTION=database y RUN_QUEUE_WORKER=true en Render.'
            );
        }

        $existente = $this->corridaEnCurso();
        if ($existente !== null) {
            return $existente;
        }

        $plan = $this->oportunidades->planBusqueda();
        if ($plan['error'] !== null) {
            throw new RuntimeException((string) $plan['error']);
        }

        $pasos = is_array($plan['pasos'] ?? null) ? $plan['pasos'] : [];
        if ($pasos === []) {
            throw new RuntimeException('No hay palabras clave o regiones configuradas para buscar.');
        }

        $corrida = OportunidadBusquedaCorrida::query()->create([
            'usuario' => trim($usuario) ?: 'sistema',
            'fecha_busqueda' => $this->oportunidades->fechaBusquedaHoy(),
            'inicio' => now(),
            'estado' => self::ESTADO_RUNNING,
            'total_pasos' => count($pasos),
            'pasos_procesados' => 0,
            'pasos_fallidos' => 0,
            'oportunidades_encontradas' => count($this->oportunidades->listarGuardadasHoy()),
            'plan_json' => $pasos,
            'errores_json' => [],
            'mensaje' => 'Búsqueda encolada.',
        ]);

        ProcessOportunidadBusquedaJob::dispatch($corrida->id);

        return $corrida;
    }

    public function procesar(OportunidadBusquedaCorrida $corrida): void
    {
        while ($this->procesarPaso($corrida)) {
            $corrida->refresh();
        }
    }

    /**
     * Procesa un único paso. El avance condicional evita que dos jobs de retoma
     * incrementen el índice dos veces si coinciden después de un reinicio.
     */
    public function procesarPaso(OportunidadBusquedaCorrida $corrida): bool
    {
        $corrida->refresh();
        if ($corrida->estado !== self::ESTADO_RUNNING) {
            return false;
        }

        $pasos = is_array($corrida->plan_json) ? $corrida->plan_json : [];
        $indice = (int) $corrida->pasos_procesados;
        if ($indice >= count($pasos)) {
            $this->finalizar($corrida);

            return false;
        }

        $paso = is_array($pasos[$indice] ?? null) ? $pasos[$indice] : [];
        $frase = trim((string) ($paso['frase'] ?? ''));
        $region = (int) ($paso['region'] ?? 0);
        $errores = is_array($corrida->errores_json) ? $corrida->errores_json : [];
        $fallidos = (int) $corrida->pasos_fallidos;

        try {
            $this->oportunidades->ejecutarPaso($frase, $region, [], null);
            $mensaje = sprintf(
                'Paso %d/%d completado: «%s» · región %d.',
                $indice + 1,
                count($pasos),
                $frase,
                $region,
            );
        } catch (\Throwable $e) {
            $errores[] = [
                'indice' => $indice,
                'frase' => $frase,
                'region' => $region,
                'mensaje' => mb_substr($e->getMessage(), 0, 500),
                'fecha' => now()->toIso8601String(),
            ];
            $fallidos++;
            $mensaje = sprintf(
                'Paso %d/%d falló; se continúa con el siguiente: %s',
                $indice + 1,
                count($pasos),
                mb_substr($e->getMessage(), 0, 300),
            );
        }

        $actualizada = OportunidadBusquedaCorrida::query()
            ->whereKey($corrida->id)
            ->where('estado', self::ESTADO_RUNNING)
            ->where('pasos_procesados', $indice)
            ->update([
                'pasos_procesados' => $indice + 1,
                'pasos_fallidos' => $fallidos,
                'oportunidades_encontradas' => count($this->oportunidades->listarGuardadasHoy()),
                'errores_json' => json_encode(array_slice($errores, -100), JSON_UNESCAPED_UNICODE),
                'mensaje' => $mensaje,
                'updated_at' => now(),
            ]);

        $corrida->refresh();
        if ($actualizada !== 1) {
            return false;
        }

        if ((int) $corrida->pasos_procesados >= count($pasos)) {
            $this->finalizar($corrida);

            return false;
        }

        return $corrida->estado === self::ESTADO_RUNNING;
    }

    public function cancelar(?OportunidadBusquedaCorrida $corrida = null): ?OportunidadBusquedaCorrida
    {
        $corrida ??= $this->corridaEnCurso();
        if ($corrida === null) {
            return null;
        }

        $corrida->fill([
            'estado' => self::ESTADO_CANCELLED,
            'fin' => now(),
            'mensaje' => 'Búsqueda cancelada por el usuario.',
        ])->save();

        return $corrida;
    }

    /**
     * Reanuda una corrida activa o crea la corrida omitida del último horario programado.
     *
     * @return array{accion: string, mensaje: string, corrida_id: int|null}
     */
    public function catchUp(string $usuario = 'sistema', bool $reanudarActiva = true): array
    {
        if (! $this->habilitada()) {
            return ['accion' => 'omitido', 'mensaje' => 'Búsqueda automática deshabilitada en este sitio.', 'corrida_id' => null];
        }

        $activa = $this->corridaEnCurso();
        if ($activa !== null) {
            if ($reanudarActiva) {
                ProcessOportunidadBusquedaJob::dispatch($activa->id);
            }

            return [
                'accion' => $reanudarActiva ? 'reanudada' : 'en_curso',
                'mensaje' => $reanudarActiva
                    ? 'Corrida de oportunidades reanudada.'
                    : 'Ya hay una corrida de oportunidades en curso.',
                'corrida_id' => $activa->id,
            ];
        }

        $slot = $this->ultimoHorarioProgramado();
        if ($slot === null) {
            return ['accion' => 'omitido', 'mensaje' => 'No hay horarios programados válidos.', 'corrida_id' => null];
        }

        $yaEjecutada = OportunidadBusquedaCorrida::query()
            ->where('inicio', '>=', $slot)
            ->exists();
        if ($yaEjecutada) {
            return ['accion' => 'omitido', 'mensaje' => 'El último horario programado ya tiene corrida.', 'corrida_id' => null];
        }

        $corrida = $this->iniciar($usuario);

        return ['accion' => 'encolada', 'mensaje' => 'Catch-up de oportunidades encolado.', 'corrida_id' => $corrida->id];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function estado(?OportunidadBusquedaCorrida $corrida = null): ?array
    {
        $corrida ??= $this->ultimaCorrida();
        if ($corrida === null) {
            return null;
        }

        $total = max(0, (int) $corrida->total_pasos);
        $procesados = min($total, (int) $corrida->pasos_procesados);

        return [
            'id' => $corrida->id,
            'estado' => $corrida->estado,
            'inicio' => $corrida->inicio?->toIso8601String(),
            'fin' => $corrida->fin?->toIso8601String(),
            'total_pasos' => $total,
            'pasos_procesados' => $procesados,
            'pasos_fallidos' => (int) $corrida->pasos_fallidos,
            'oportunidades_encontradas' => (int) $corrida->oportunidades_encontradas,
            'progreso' => $total > 0 ? min(100, (int) round(($procesados / $total) * 100)) : 0,
            'mensaje' => $corrida->mensaje,
            'errores' => is_array($corrida->errores_json) ? $corrida->errores_json : [],
            'items' => $this->oportunidades->listarGuardadasHoy(),
        ];
    }

    private function ultimoHorarioProgramado(): ?Carbon
    {
        $horas = collect(explode(',', (string) config('cotiz.mercadopublico.resultados_schedule_hours', '10,19')))
            ->map(fn ($hora) => (int) trim((string) $hora))
            ->filter(fn (int $hora) => $hora >= 0 && $hora <= 23)
            ->unique()
            ->sort()
            ->values();

        if ($horas->isEmpty()) {
            return null;
        }

        $ahora = now()->timezone((string) config('app.timezone', 'America/Santiago'));
        foreach ($horas->reverse() as $hora) {
            $candidato = $ahora->copy()->startOfDay()->addHours($hora);
            if ($candidato->lessThanOrEqualTo($ahora)) {
                return $candidato;
            }
        }

        return $ahora->copy()->subDay()->startOfDay()->addHours((int) $horas->last());
    }

    private function finalizar(OportunidadBusquedaCorrida $corrida): void
    {
        $corrida->refresh();
        if ($corrida->estado !== self::ESTADO_RUNNING) {
            return;
        }

        $fallidos = (int) $corrida->pasos_fallidos;
        $corrida->fill([
            'estado' => self::ESTADO_COMPLETED,
            'fin' => now(),
            'oportunidades_encontradas' => count($this->oportunidades->listarGuardadasHoy()),
            'mensaje' => $fallidos > 0
                ? 'Búsqueda terminada con '.$fallidos.' paso(s) fallido(s).'
                : 'Búsqueda terminada correctamente.',
        ])->save();
    }
}
