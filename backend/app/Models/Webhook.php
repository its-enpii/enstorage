<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'label',
    'url',
    'secret',
    'events',
    'is_active',
    'last_triggered_at',
    'last_status',
])]
#[Hidden(['secret'])]
class Webhook extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const EVENTS = [
        'file.upload.completed',
        'file.upload.failed',
        'file.deleted',
        'file.shared',
        'folder.shared',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'last_triggered_at' => 'datetime',
            'last_status' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscribesTo(string $event): bool
    {
        if (! $this->is_active) return false;
        return in_array($event, $this->events ?? [], true);
    }
}
