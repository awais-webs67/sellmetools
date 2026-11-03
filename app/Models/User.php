<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

#[Fillable([
    'name',
    'email',
    'phone',
    'password',
    'is_admin',
    'admin_role',
    'wallet_balance',
    'is_active',
    'is_reseller',
    'reseller_slug',
    'reseller_discount_percent',
    'reseller_notes',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_SALES = 'sales';

    public const ROLE_SEO = 'seo';

    public static function adminRoleOptions(): array
    {
        return [
            '' => '👤 Regular Customer / Reseller (No Admin Panel Access)',
            self::ROLE_SUPER_ADMIN => '👑 Super Admin (Full Store, Suppliers & Settings Access)',
            self::ROLE_SALES => '🛍️ Sales & Order Support (Orders, Payments, Products & Stock Only)',
            self::ROLE_SEO => '🔍 SEO & Content Specialist (Products SEO/Content, Categories, Media & SEO Settings Only)',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if (filled($user->phone)) {
                $user->phone = format_whatsapp($user->phone);
            }
            if (in_array($user->admin_role, [self::ROLE_SUPER_ADMIN, self::ROLE_SALES, self::ROLE_SEO], true)) {
                $user->is_admin = true;
            } elseif ($user->isDirty('admin_role') && blank($user->admin_role)) {
                $user->is_admin = false;
                $user->admin_role = null;
            } elseif ($user->is_admin && blank($user->admin_role)) {
                $user->admin_role = self::ROLE_SUPER_ADMIN;
            }
            if ($user->is_reseller && blank($user->reseller_slug)) {
                $base = Str::slug($user->name ?: explode('@', (string) $user->email)[0]) ?: 'reseller';
                $slug = $base;
                $i = 2;
                while (static::where('reseller_slug', $slug)->where('id', '!=', $user->id ?? 0)->exists()) {
                    $slug = "{$base}-{$i}";
                    $i++;
                }
                $user->reseller_slug = $slug;
            }
            if (filled($user->reseller_slug)) {
                $user->reseller_slug = Str::slug($user->reseller_slug);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'wallet_balance' => 'decimal:2',
            'is_active' => 'boolean',
            'is_reseller' => 'boolean',
            'reseller_discount_percent' => 'decimal:2',
        ];
    }

    public function canAccessAdminPanel(): bool
    {
        return (bool) ($this->is_active ?? true)
            && ((bool) $this->is_admin || in_array($this->admin_role, [self::ROLE_SUPER_ADMIN, self::ROLE_SALES, self::ROLE_SEO], true));
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->canAccessAdminPanel();
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_admin && in_array($this->admin_role, [null, '', self::ROLE_SUPER_ADMIN], true);
    }

    public function isSalesStaff(): bool
    {
        return $this->admin_role === self::ROLE_SALES;
    }

    public function isSeoStaff(): bool
    {
        return $this->admin_role === self::ROLE_SEO;
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function resellerProductPrices(): HasMany
    {
        return $this->hasMany(ResellerProductPrice::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest();
    }

    /**
     * Atomically adjusts the user's wallet balance with row-level lock and audit log.
     */
    public function adjustWallet(
        float $delta,
        string $type,
        ?string $note = null,
        ?int $adminId = null,
        ?int $orderId = null
    ): WalletTransaction {
        return DB::transaction(function () use ($delta, $type, $note, $adminId, $orderId) {
            /** @var User $locked */
            $locked = static::query()->lockForUpdate()->findOrFail($this->id);

            $current = (float) $locked->wallet_balance;
            $next = round($current + $delta, 2);

            if ($next < 0) {
                throw new RuntimeException('Insufficient wallet balance.');
            }

            $locked->wallet_balance = $next;
            $locked->save();

            $this->wallet_balance = $locked->wallet_balance;

            return WalletTransaction::create([
                'user_id' => $locked->id,
                'admin_id' => $adminId,
                'order_id' => $orderId,
                'type' => $type,
                'amount' => abs($delta),
                'balance_after' => $next,
                'note' => $note,
            ]);
        });
    }
}
