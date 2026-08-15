<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'Users';
    protected $primaryKey = 'UserID';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'UserCode',
        'UserName',
        'PasswordHash',
        'FirstName',
        'LastName',
        'Email',
        'Mobile',
        'IsActive',
        'IsLocked',
        'FailedLoginCount',
        'RememberToken',
    ];

    protected $hidden = [
        'PasswordHash',
        'RowGuid',
        'RememberToken',
    ];

    protected $casts = [
        'IsActive' => 'boolean',
        'IsLocked' => 'boolean',
        'CreateDate' => 'datetime',
        'ModifyDate' => 'datetime',
        'LastLoginDate' => 'datetime',
    ];

    /**
     * لاراول برای لاگین از این متد استفاده می‌کنه
     * ما PasswordHash رو برمی‌گردونیم
     */
    public function getAuthPassword()
    {
        return $this->PasswordHash;
    }

    /**
     * نام ستون remember token
     */
    public function getRememberTokenName()
    {
        return 'RememberToken';
    }

    /**
     * چک کردن اینکه کاربر فعال و باز هست یا نه
     */
    public function canLogin(): bool
    {
        return $this->IsActive && !$this->IsLocked;
    }

    /**
     * اسم کامل کاربر
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->FirstName . ' ' . $this->LastName);
    }
}