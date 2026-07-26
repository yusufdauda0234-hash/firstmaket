<?php

namespace App\Models;

use App\Modules\Auth\Models\SocialAccount;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Vendor\Models\VendorProfile;
use App\Modules\Wallet\Models\Wallet;
use App\Shared\Enums\UserStatus;
use App\Shared\Enums\UserType;
use App\Shared\Traits\HasUuid;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Throwable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property UserType $user_type
 * @property UserStatus $status
 * @property string|null $status_reason
 * @property int|null $status_changed_by
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $two_factor_confirmed_at
 * @property-read CustomerProfile|null $customerProfile
 * @property-read VendorProfile|null $vendorProfile
 * @property-read Wallet|null $wallet
 * @property-read Collection<int, SocialAccount> $socialAccounts
 * @property-read User|null $statusChangedBy
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, HasUuid, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'user_type',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'user_type' => UserType::class,
            'status' => UserStatus::class,
            'status_changed_at' => 'datetime',
        ];
    }

    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class);
    }

    public function vendorProfile(): HasOne
    {
        return $this->hasOne(VendorProfile::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Overrides the MustVerifyEmail trait default to swallow send failures:
     * the verification link is a resend-able side effect, not part of the
     * registration transaction, so a mail-provider hiccup must never fail
     * (or, worse, hang and fatal-error) the request that creates the account.
     */
    public function sendEmailVerificationNotification(): void
    {
        try {
            $this->notify(new VerifyEmail);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
