<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Printer extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'name',
        'ip_address',
        'port',
        'type',
        'linked_pos_register_id',
        'print_on_direct',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'print_on_direct' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function routes()
    {
        return $this->hasMany(PrintRoute::class);
    }

    public function linkedPosRegister()
    {
        return $this->belongsTo(PosRegister::class, 'linked_pos_register_id');
    }

    public function departments()
    {
        return $this->belongsToMany(Department::class, 'printer_department');
    }

    public function items()
    {
        return $this->belongsToMany(Item::class, 'printer_item');
    }

    // ── Scopes ───────────────────────────────────────────────

    public function scopeActive($query, int $branchId)
    {
        return $query->where('branch_id', $branchId)
                     ->where('is_active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // ── Helpers ──────────────────────────────────────────────

    /**
     * اختبار الاتصال بالطابعة عبر TCP Socket
     */
    public function testConnection(): array
    {
        $ip = $this->ip_address;
        $port = (int) $this->port;
        $timeout = 3;

        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);

        if ($fp) {
            fclose($fp);
            return [
                'success' => true,
                'message' => "تم الاتصال بالطابعة {$this->name} بنجاح",
            ];
        }

        return [
            'success' => false,
            'message' => "فشل الاتصال: {$errstr} ({$errno})",
        ];
    }
}
