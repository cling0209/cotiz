<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    protected $table = 'order_status_history';

    protected $fillable = ['order_id', 'from_status', 'to_status', 'note', 'changed_by'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
