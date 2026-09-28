<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجل تغييرات حالة التيك أواي وتعيين السائق — مين غيّر (أي تطبيق خارجي) ومتى. */
class TakeawayStatusEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'external_api_client_id', 'event', 'from_status', 'to_status', 'driver', 'note', 'created_at'];

    protected $casts = [
        'driver' => 'array',
        'created_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(ExternalApiClient::class, 'external_api_client_id');
    }
}
