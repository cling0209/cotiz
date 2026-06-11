<?php

namespace App\Services\Payments;

use App\Models\Order;

interface PaymentGatewayInterface
{
    public function createTransaction(Order $order): array;

    public function commitTransaction(string $token): array;
}
