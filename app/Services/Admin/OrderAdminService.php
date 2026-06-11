<?php

namespace App\Services\Admin;

use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderAdminService
{
    public function defaultDateFrom(): Carbon
    {
        return now()->subDays(6)->startOfDay();
    }

    public function defaultDateTo(): Carbon
    {
        return now()->endOfDay();
    }

    /**
     * @return array{from: Carbon, to: Carbon, from_input: string, to_input: string}
     */
    public function resolveDateRange(Request $request): array
    {
        $fromInput = $request->query('date_from');
        $toInput = $request->query('date_to');

        $from = $fromInput
            ? Carbon::parse($fromInput)->startOfDay()
            : $this->defaultDateFrom();

        $to = $toInput
            ? Carbon::parse($toInput)->endOfDay()
            : $this->defaultDateTo();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [
            'from' => $from,
            'to' => $to,
            'from_input' => $from->toDateString(),
            'to_input' => $to->toDateString(),
        ];
    }

    public function filteredQuery(Request $request): Builder
    {
        $dates = $this->resolveDateRange($request);

        return Order::query()
            ->withCount('items')
            ->whereBetween('created_at', [$dates['from'], $dates['to']])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->query('payment_status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->query('q');
                $q->where(function ($inner) use ($term) {
                    $inner->where('uuid', 'ilike', "%{$term}%")
                        ->orWhere('customer_email', 'ilike', "%{$term}%")
                        ->orWhere('customer_name', 'ilike', "%{$term}%");
                });
            })
            ->orderByDesc('created_at');
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $dates = $this->resolveDateRange($request);
        $filename = 'ventas_'.$dates['from_input'].'_'.$dates['to_input'].'.csv';

        return response()->streamDownload(function () use ($request) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Fecha',
                'Pedido',
                'Cliente',
                'Email',
                'Items',
                'Subtotal',
                'Envío',
                'Total',
                'Estado pedido',
                'Estado pago',
                'Método pago',
                'Región',
                'Comuna',
            ], ';');

            $this->filteredQuery($request)
                ->withCount('items')
                ->chunk(200, function ($orders) use ($handle) {
                    foreach ($orders as $order) {
                        fputcsv($handle, [
                            $order->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                            $order->uuid,
                            $order->customer_name,
                            $order->customer_email,
                            $order->items_count,
                            number_format((float) $order->subtotal, 0, '', ''),
                            number_format((float) $order->shipping_amount, 0, '', ''),
                            number_format((float) $order->total, 0, '', ''),
                            order_status_label($order->status),
                            payment_status_label($order->payment_status),
                            $order->payment_method ?? '',
                            $order->shipping_region,
                            $order->shipping_comuna,
                        ], ';');
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
