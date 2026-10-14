<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => AuditLog::record('created', $model, [], $model->auditValues($model->getAttributes())));

        static::updated(function (Model $model) {
            $changes = $model->auditValues($model->getChanges());
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            $old = array_intersect_key($model->auditValues($model->getOriginal()), $changes);
            AuditLog::record('updated', $model, $old, $changes);
        });

        static::deleted(fn (Model $model) => AuditLog::record('deleted', $model, $model->auditValues($model->getAttributes()), []));
    }

    /** @param array<string, mixed> $values */
    protected function auditValues(array $values): array
    {
        $hidden = array_merge($this->getHidden(), ['password', 'remember_token', 'certificate_password', 'certificate_content']);

        return array_diff_key($values, array_flip($hidden));
    }
}
