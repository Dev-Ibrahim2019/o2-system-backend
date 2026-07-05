<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PosRegister extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'code',
        'name',
        'device_uuid',
        'activation_token',
        'token_expires_at',
        'status'
    ];

    // حماية التواريخ ليتعرف عليها لارافيل كـ Carbon Instance
    protected $casts = [
        'token_expires_at' => 'datetime',
    ];

    // علاقة نقطة البيع بالفرع التابعة له
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // ═══════════════════════════════════════════════════════════
    //  🛡️ Boot Events — للحماية والتوليد التلقائي
    // ═══════════════════════════════════════════════════════════
    protected static function booted(): void
    {
        // ── 1. عند الإنشاء (creating): توليد كود POS تلقائياً ──
        static::creating(function (PosRegister $register) {
            if (empty($register->code)) {
                // جلب آخر سجل تم إنشاؤه لاستخراج رقمه
                $lastRegister = static::orderBy('id', 'desc')->first();

                // إذا كان هناك سابق، خذ رقم الكود الأكبر وزد 1
                if ($lastRegister) {
                    // استخراج الرقم من آخر كود (مثال: POS-015 → 15)
                    $lastNumber = (int) Str::after($lastRegister->code, 'POS-');
                    $nextNumber = $lastNumber + 1;
                } else {
                    // أول نقطة بيع في النظام → تبدأ من 1
                    $nextNumber = 1;
                }

                // توليد الكود بالتنسيق POS-001, POS-002, ...
                $register->code = 'POS-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
            }
        });

        // ── 2. عند التحديث (updating): منع تعديل الكود نهائياً ─
        static::updating(function (PosRegister $register) {
            // إذا كان حقل code موجوداً في البيانات المرسلة،
            // نعيده إلى قيمته الأصلية (الأوتوماتيكية) لمنع التلاعب
            if ($register->isDirty('code')) {
                $register->code = $register->getOriginal('code');
            }
        });
    }
}
