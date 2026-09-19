<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    // الحقول التي لا نريد تسجيل تغييراتها (كلمات المرور وغيرها)
    private array $excluded = ['password', 'pin', 'remember_token'];

    public function created(Model $model): void
    {
        $attributes = array_diff_key($model->getAttributes(), array_flip($this->excluded));
        $this->log($model, 'created', [], $attributes);
    }

    public function updated(Model $model): void
    {
        $dirty = $model->getDirty();
        $dirty = array_diff_key($dirty, array_flip($this->excluded));

        if (empty($dirty)) return;

        $old = array_intersect_key($model->getOriginal(), $dirty);

        $this->log($model, 'updated', $old, $dirty);
    }

    public function deleted(Model $model): void
    {
        $this->log($model, 'deleted', $model->getOriginal(), []);
    }

    public function restored(Model $model): void
    {
        $this->log($model, 'restored', [], []);
    }

    private function log(Model $model, string $event, array $old, array $new): void
    {
        // نتجنب N+1 بكتابة مباشرة بدون Model Events
        AuditLog::insert([
            'auditable_type' => get_class($model),
            'auditable_id'   => $model->getKey(),
            'event'          => $event,
            'old_values'     => json_encode($old),
            'new_values'     => json_encode($new),
            'user_id'        => auth()->id(),
            'ip_address'     => request()->ip(),
            'user_agent'     => substr(request()->userAgent() ?? '', 0, 255),
            'created_at'     => now(),
        ]);
    }
}
