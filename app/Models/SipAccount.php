<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * بيانات اعتماد SIP لحساب سماعة موظف (Browser-Phone / أي Softphone متوافق).
 * لا تحتوي على أي قيم اتصال افتراضية حقيقية — تُدخَل يدوياً من واجهة الإعدادات.
 */
class SipAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'account_name',
        'username',
        'password',
        'sip_server',
        'websocket_port',
        'server_path',
        'domain',
        'transport',
        'register_refresh',
        'keep_alive',
        'is_active',
        'user_id',
        'branch_id',
    ];

    protected $hidden = [
        'password',
    ];

    // ملاحظة: لم أستخدم cast 'encrypted' هنا لأن بيئة PHP CLI الحالية تفتقد امتداد
    // openssl (تحقّقتُ: حتى Illuminate\Support\Facades\Crypt الأساسي يفشل بنفس الخطأ)،
    // فلم يمكن التأكد أن السيرفر الفعلي يدعمه دون المخاطرة بانهيار الحفظ. كلمة السر
    // تُخزَّن حالياً كنص عادي (ومخفية من أي استجابة API عبر hidden أعلاه) — يُنصح
    // بتفعيل 'password' => 'encrypted' بعد التأكد أن openssl متاح في PHP الإنتاج.
    protected $casts = [
        'is_active' => 'boolean',
        'register_refresh' => 'integer',
        'keep_alive' => 'integer',
        'websocket_port' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
