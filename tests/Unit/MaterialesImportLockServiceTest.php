<?php

namespace Tests\Unit;

use App\Services\MaterialesImportLockService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class MaterialesImportLockServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(MaterialesImportLockService::CACHE_KEY);
    }

    public function test_second_acquire_is_blocked(): void
    {
        $service = app(MaterialesImportLockService::class);
        $lockA = (string) Str::uuid();
        $lockB = (string) Str::uuid();

        $service->acquire(1, 'usuario_a', $lockA, 'pdf', 'pedido.pdf');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Hay un análisis en curso');

        $service->acquire(2, 'usuario_b', $lockB, 'excel', 'lista.xlsx');
    }

    public function test_same_lock_id_can_reacquire_and_touch(): void
    {
        $service = app(MaterialesImportLockService::class);
        $lockId = (string) Str::uuid();

        $service->acquire(1, 'usuario_a', $lockId, 'pdf', 'pedido.pdf');
        $service->assertOwnerOrRenew($lockId);

        $current = $service->current();
        $this->assertSame($lockId, $current['lock_id'] ?? null);
    }

    public function test_release_clears_lock(): void
    {
        $service = app(MaterialesImportLockService::class);
        $lockId = (string) Str::uuid();

        $service->acquire(1, 'usuario_a', $lockId, 'pdf', 'pedido.pdf');
        $service->release($lockId);

        $this->assertNull($service->current());
    }

    public function test_abandoned_lock_is_cleared(): void
    {
        $lockId = (string) Str::uuid();
        Cache::put(MaterialesImportLockService::CACHE_KEY, [
            'user_id' => 1,
            'username' => 'viejo',
            'lock_id' => $lockId,
            'tipo' => 'pdf',
            'original_name' => 'old.pdf',
            'started_at' => now()->subHours(2)->toIso8601String(),
            'last_touch_at' => now()->subHours(2)->toIso8601String(),
        ], 60);

        $service = app(MaterialesImportLockService::class);

        $this->assertNull($service->currentOrReleaseIfAbandoned());
        $this->assertNull($service->current());
    }
}
