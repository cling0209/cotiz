<?php

namespace Tests\Unit;

use App\Support\HoraChile;
use Carbon\Carbon;
use Tests\TestCase;

class HoraChileTest extends TestCase
{
    public function test_formatea_utc_a_hora_chile(): void
    {
        config(['app.timezone' => 'America/Santiago']);

        $utc = Carbon::parse('2026-08-20 09:00:01', 'UTC');

        $this->assertSame('20/08/2026 05:00:01', HoraChile::format($utc));
        $this->assertSame('20/08/2026 05:00', HoraChile::format($utc, 'd/m/Y H:i'));
    }

    public function test_nulo_devuelve_guion(): void
    {
        $this->assertSame('—', HoraChile::format(null));
        $this->assertSame('—', HoraChile::format(''));
    }
}
