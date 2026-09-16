<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'user_id', 'group_id', 'subject_type', 'subject_id', 'action', 'description', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public static function record(string $action, string $description, ?int $userId = null, ?int $groupId = null, mixed $subject = null, array $meta = []): self
    {
        return static::create([
            'user_id' => $userId,
            'group_id' => $groupId,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'action' => $action,
            'description' => $description,
            'meta' => $meta,
        ]);
    }
}
