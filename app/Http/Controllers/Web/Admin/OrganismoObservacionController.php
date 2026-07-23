<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganismoObservacion;
use App\Services\OrganismoObservacionRelayService;
use App\Services\OrganismoObservacionService;
use App\Services\OrganismoPerfilAutomaticoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class OrganismoObservacionController extends Controller
{
    public function __construct(
        protected OrganismoObservacionService $service,
        protected OrganismoObservacionRelayService $relay,
        protected OrganismoPerfilAutomaticoService $perfilAutomatico,
    ) {}

    public function index(Request $request): View
    {
        $organismos = $this->service->listar(
            $request->string('q')->trim()->toString() ?: null,
            20,
        );

        return view('admin.organismos-observaciones.index', [
            'organismos' => $organismos,
            'puedeAnalizar' => $this->perfilAutomatico->analisisHabilitado(),
        ]);
    }

    public function edit(OrganismoObservacion $organismo): View
    {
        return view('admin.organismos-observaciones.form', [
            'organismo' => $organismo,
            'puedeAnalizar' => $this->perfilAutomatico->analisisHabilitado(),
        ]);
    }

    public function update(Request $request, OrganismoObservacion $organismo): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['nullable', 'string', 'max:200'],
            'observacion' => ['nullable', 'string', 'max:5000'],
        ], [
            'observacion.max' => 'La observación no puede superar 5000 caracteres.',
        ]);

        $this->service->actualizar(
            $organismo,
            (string) ($datos['nombre'] ?? ''),
            (string) ($datos['observacion'] ?? ''),
            $request->user()?->id,
        );

        $organismo->refresh();
        $local = trim((string) config('cotiz.sistema', config('app.name', 'este sistema')));
        $mensaje = 'Observación guardada en '.$local.'.';

        try {
            $mensajeRemoto = $this->relay->replicarAdmin($organismo);

            return redirect()
                ->route('admin.organismos-observaciones.index', array_filter([
                    'q' => $request->string('q')->trim()->toString() ?: null,
                ]))
                ->with('success', $mensaje.' '.$mensajeRemoto);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.organismos-observaciones.index', array_filter([
                    'q' => $request->string('q')->trim()->toString() ?: null,
                ]))
                ->with('success', $mensaje)
                ->with('error', $e->getMessage());
        }
    }

    public function analizar(Request $request): RedirectResponse
    {
        if (! $this->perfilAutomatico->analisisHabilitado()) {
            return redirect()
                ->route('admin.organismos-observaciones.index')
                ->with('error', 'El análisis automático solo corre donde MERCADOPUBLICO_ANALISIS_ADMIN=true.');
        }

        $stats = $this->perfilAutomatico->recalcularTodos();
        $mensaje = "Perfiles recalculados: {$stats['organismos']} organismos ({$stats['con_perfil']} con perfil, {$stats['sin_historial']} sin historial).";

        try {
            $sync = $this->relay->empujarTodos();
            $mensaje .= " Sync al par: {$sync['ok']} OK";
            if ($sync['fail'] > 0) {
                $mensaje .= ", {$sync['fail']} fallos";
            }
            $mensaje .= '.';

            return redirect()
                ->route('admin.organismos-observaciones.index', array_filter([
                    'q' => $request->string('q')->trim()->toString() ?: null,
                ]))
                ->with('success', $mensaje);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.organismos-observaciones.index')
                ->with('success', $mensaje)
                ->with('error', $e->getMessage());
        }
    }

    public function resetDesdeCerradas(Request $request): RedirectResponse
    {
        $stats = $this->service->resetDesdeCerradas();
        $mensaje = "Reinicio desde cerradas: borrados {$stats['borrados']}, creados {$stats['creados']}.";

        if ($this->perfilAutomatico->analisisHabilitado()) {
            $p = $this->perfilAutomatico->recalcularTodos();
            $mensaje .= " Perfiles: {$p['con_perfil']} con datos, {$p['sin_historial']} sin historial.";
        }

        try {
            $this->relay->purgarPar();
            $sync = $this->relay->empujarTodos();
            $mensaje .= " Sync al par: {$sync['ok']} OK";
            if ($sync['fail'] > 0) {
                $mensaje .= ", {$sync['fail']} fallos";
            }
            $mensaje .= '.';

            return redirect()
                ->route('admin.organismos-observaciones.index')
                ->with('success', $mensaje);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.organismos-observaciones.index')
                ->with('success', $mensaje)
                ->with('error', $e->getMessage());
        }
    }
}
