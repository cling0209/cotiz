<?php

namespace App\Services;

class ProductImportProgress
{
    public function __construct(
        private readonly ?\Closure $callback = null,
    ) {}

    public function phase(string $message): void
    {
        $this->emit('phase', ['message' => $message]);
    }

    public function progressStart(int $max, string $label = ''): void
    {
        $this->emit('progress_start', ['max' => $max, 'label' => $label]);
    }

    public function progressAdvance(int $current): void
    {
        $this->emit('progress', ['current' => $current]);
    }

    public function progressFinish(): void
    {
        $this->emit('progress_finish', []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(string $event, array $payload = []): void
    {
        if ($this->callback === null) {
            return;
        }

        ($this->callback)($event, $payload);
    }
}
