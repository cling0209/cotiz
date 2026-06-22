<?php

namespace App\Console\Commands;

use App\Services\CompraAgilSyncService;
use Illuminate\Console\Command;

class SyncCompraAgilCommand extends Command
{
    protected $signature = 'compra-agil:sync';

    protected $description = 'Sincroniza Compras Ágiles adjudicadas desde Mercado Público';

    public function handle(CompraAgilSyncService $sync): int
    {
        if (! config('cotiz.mercadopublico.analisis_admin_habilitado', false)) {
            $this->warn('Análisis Compra Ágil deshabilitado (MERCADOPUBLICO_ANALISIS_ADMIN=false).');

            return self::SUCCESS;
        }

        $this->info('Iniciando sync Compra Ágil...');
        $resultado = $sync->sincronizarAdjudicadas(usuario: 'sistema');

        if ($resultado['error']) {
            $this->error($resultado['error']);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Listados: %d | Códigos: %d | Detalles: %d | Nuevos: %d',
            $resultado['listados'],
            $resultado['codigos_encontrados'] ?? 0,
            $resultado['detalles'],
            $resultado['procesos_nuevos'],
        ));

        return self::SUCCESS;
    }
}
