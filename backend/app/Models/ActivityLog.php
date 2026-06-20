<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address', 'user_agent'])]
class ActivityLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public const ACTION_USER_LOGIN = 'USER_LOGIN';
    public const ACTION_USER_LOGOUT = 'USER_LOGOUT';
    public const ACTION_USER_REGISTER = 'USER_REGISTER';
    public const ACTION_USER_UPDATE = 'USER_UPDATE';
    public const ACTION_USER_PASSWORD_CHANGE = 'USER_PASSWORD_CHANGE';
    public const ACTION_GOOGLE_ACCOUNT_ADD = 'GOOGLE_ACCOUNT_ADD';
    public const ACTION_GOOGLE_ACCOUNT_REMOVE = 'GOOGLE_ACCOUNT_REMOVE';
    public const ACTION_GOOGLE_ACCOUNT_QUOTA_SYNC = 'GOOGLE_ACCOUNT_QUOTA_SYNC';
    public const ACTION_FOLDER_CREATE = 'FOLDER_CREATE';
    public const ACTION_FOLDER_RENAME = 'FOLDER_RENAME';
    public const ACTION_FOLDER_MOVE = 'FOLDER_MOVE';
    public const ACTION_FOLDER_DELETE = 'FOLDER_DELETE';
    public const ACTION_FOLDER_STAR = 'FOLDER_STAR';
    public const ACTION_FILE_UPLOAD = 'FILE_UPLOAD';
    public const ACTION_FILE_UPLOAD_FAILED = 'FILE_UPLOAD_FAILED';
    public const ACTION_FILE_RENAME = 'FILE_RENAME';
    public const ACTION_FILE_MOVE = 'FILE_MOVE';
    public const ACTION_FILE_DELETE = 'FILE_DELETE';
    public const ACTION_FILE_STAR = 'FILE_STAR';
    public const ACTION_API_KEY_CREATE = 'API_KEY_CREATE';
    public const ACTION_API_KEY_REVOKE = 'API_KEY_REVOKE';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
