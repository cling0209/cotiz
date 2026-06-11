<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Admin\OrderAdminService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderController extends Controller
{
    public function __construct(protected OrderAdminService $orderAdmin) {}

    public function index(Request $request): View
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $dates = $this->orderAdmin->resolveDateRange($request);

        $orders = $this->orderAdmin->filteredQuery($request)
            ->paginate(20)
            ->withQueryString();

        return view('admin.orders.index', [
            'orders' => $orders,
            'dateFrom' => $dates['from_input'],
            'dateTo' => $dates['to_input'],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        return $this->orderAdmin->exportCsv($request);
    }

    public function show(Order $order): View
    {
        $order->load([
            'items',
            'paymentTransactions',
            'statusHistory',
            'user',
        ]);

        return view('admin.orders.show', compact('order'));
    }
}
