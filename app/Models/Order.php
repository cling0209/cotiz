<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'user_id', 'status', 'payment_status', 'payment_method',
        'subtotal', 'shipping_amount', 'shipping_zone', 'shipping_total_weight_kg',
        'shipping_rate_type', 'shipping_rate_label', 'shipping_weight_rate_id',
        'shipping_comuna_weight_rate_id',
        'shipping_metadata', 'total', 'currency',
        'shipping_recipient_name', 'shipping_phone', 'shipping_region',
        'shipping_comuna', 'shipping_street', 'shipping_street_number',
        'shipping_apartment', 'customer_email', 'customer_name',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_amount' => 'decimal:2',
            'shipping_total_weight_kg' => 'decimal:3',
            'shipping_metadata' => 'array',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (! $order->uuid) {
                $order->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function shippingWeightRate(): BelongsTo
    {
        return $this->belongsTo(ShippingWeightRate::class, 'shipping_weight_rate_id');
    }

    public function shippingComunaWeightRate(): BelongsTo
    {
        return $this->belongsTo(ShippingComunaWeightRate::class, 'shipping_comuna_weight_rate_id');
    }

    public function recordStatus(string $toStatus, ?string $fromStatus = null, ?string $note = null): void
    {
        $this->statusHistory()->create([
            'from_status' => $fromStatus ?? $this->status,
            'to_status' => $toStatus,
            'note' => $note,
            'changed_by' => auth()->id(),
        ]);
        $this->update(['status' => $toStatus]);
    }
}
