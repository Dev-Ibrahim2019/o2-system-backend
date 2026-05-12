<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Item extends Model
{

    protected $fillable = [
        'department_id',
        'name',
        'name_ar',
        'code',
        'image',
        'unit',
        'is_active'
    ];

    protected $appends = [
        'image_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        if (Storage::disk('public')->exists($this->image)) {
            return Storage::disk('public')->url($this->image);
        }

        if (Storage::disk('uploads')->exists($this->image)) {
            return Storage::disk('uploads')->url($this->image);
        }

        return Storage::disk('public')->url($this->image);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_item')
            ->withPivot(['is_active', 'price'])
            ->withTimestamps();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
