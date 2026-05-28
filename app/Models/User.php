<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'address',
        'role',
        'PhoneNumber',
        'Zalo',
        'Facebook'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];
    public function isCustomer(): bool
    {
        return $this->role === '0';
    }

    public function isAdmin(): bool
    {
        return $this->role === '1';
    }
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
    public function news()
    {
        return $this->hasMany(news::class,'news_id','id');
    }
    public function rooms()
    {
        return $this->hasMany(Room::class,'chutro_id','id');
    }
    public function notifications()
    {
        return $this->hasMany(Notification::class, 'user_id', 'id');
    }

    // ── Module Contract & Deposit ────────────────────────────────────────

    /**
     * Các hợp đồng user đang là KHÁCH THUÊ.
     * User hasMany Contract (users.id → contracts.tenant_id)
     */
    public function contractsAsTenant()
    {
        return $this->hasMany(Contract::class, 'tenant_id', 'id');
    }

    /**
     * Các hợp đồng user đang là CHỦ TRỌ.
     * User hasMany Contract (users.id → contracts.landlord_id)
     */
    public function contractsAsLandlord()
    {
        return $this->hasMany(Contract::class, 'landlord_id', 'id');
    }

    /**
     * Tất cả giao dịch tài chính user đã thực hiện.
     * User hasMany Transaction (users.id → transactions.payer_id)
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'payer_id', 'id');
    }
}
