<?php
// app/Models/Department.php  — updated version

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'nameAr',           // ← added
        'code',             // ← added (e.g. "1101")
        'parent_id',
        'type',
        'status',           // ← ACTIVE | BUSY | INACTIVE
        'is_central',
        'is_active',
        'shortName',
        'icon',
        'color',
        'location',         // ← added
        'stationNumber',
        'defaultPrepTime',
        'maxConcurrentOrders',
        'hasKds',
        'autoPrintTicket',
    ];

    protected $casts = [
        'is_active'           => 'boolean',
        'hasKds'              => 'boolean',
        'autoPrintTicket'     => 'boolean',
        'maxConcurrentOrders' => 'integer',
        'defaultPrepTime'     => 'integer',
    ];

    // ─── Relations ────────────────────────────────────────────────────────────

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_department')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /** Direct parent */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'parent_id');
    }

    /** Direct children (one level) */
    public function children(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id')->orderBy('code');
    }

    /**
     * Recursive children — used by DepartmentController::tree()
     * Eager-loads the full subtree in one call.
     */
    public function allChildren(): HasMany
    {
        return $this->hasMany(Department::class, 'parent_id')
            ->orderBy('code')
            ->with('allChildren'); // recursive eager load
    }
}
