<?php

namespace Tests\Unit;

use App\Console\Commands\SyncCompraAgilCommand;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CompraAgilAnalisisAdminFlagTest extends TestCase
{
    public function test_sync_command_no_op_cuando_analisis_deshabilitado(): void
    {
        config(['cotiz.mercadopublico.analisis_admin_habilitado' => false]);

        Artisan::call('compra-agil:sync');

        $this->assertSame(0, SyncCompraAgilCommand::SUCCESS);
        $this->assertStringContainsString(
            'deshabilitado',
            Artisan::output(),
        );
    }
}
