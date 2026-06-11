<?php

namespace App\Models;

use App\Support\WebpayPaymentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    protected $fillable = [
        'order_id',
        'gateway',
        'buy_order',
        'token',
        'amount',
        'status',
        'payment_type_code',
        'card_type',
        'card_last_four',
        'installments_number',
        'raw_response',
    ];

    protected $appends = ['payment_type_label'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'installments_number' => 'integer',
            'raw_response' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function getPaymentTypeLabelAttribute(): string
    {
        return WebpayPaymentType::label($this->payment_type_code);
    }
}
