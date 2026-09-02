<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One diary line against a customer or group occasion.
 *
 * See the create migration for why this carries no status, type or cycle year.
 */
class OccasionFollowup extends Model
{
    protected $fillable = [
        'occasion_id',
        'notes',
        'created_by',
    ];

    public function occasion(): BelongsTo
    {
        return $this->belongsTo(CustomerOccasion::class, 'occasion_id');
    }

    /**
     * Named creator(), not createdBy().
     *
     * Laravel serializes a loaded relation under the snake_case of its
     * method name, so createdBy() would write an object over the
     * created_by integer column in the JSON — the same collision that
     * made a complaint's assigned_to read as an object once assignedTo
     * was eager-loaded. Under creator the id column survives intact.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
