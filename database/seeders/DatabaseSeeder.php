<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Idempotent base data: admin account, payment methods and starter categories. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAdmin();
        $this->seedPaymentMethods();
        $this->seedCategories();
    }

    protected function seedAdmin(): void
    {
        $email = 'admin@sellmetools.test';

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $admin = new User([
            'name' => 'Admin',
            'email' => $email,
            'password' => 'password',
        ]);
        $admin->forceFill(['is_admin' => true, 'email_verified_at' => now()])->save();
    }

    protected function seedPaymentMethods(): void
    {
        $wallet = fn (string $name): string => "Send the exact amount to the {$name} account below, then enter the Transaction ID (TID) and the number you paid from.";

        $methods = [
            ['code' => 'easypaisa', 'name' => 'Easypaisa', 'currency' => 'PKR', 'requires_sender_number' => true, 'instructions' => $wallet('Easypaisa')],
            ['code' => 'jazzcash', 'name' => 'JazzCash', 'currency' => 'PKR', 'requires_sender_number' => true, 'instructions' => $wallet('JazzCash')],
            ['code' => 'nayapay', 'name' => 'NayaPay', 'currency' => 'PKR', 'requires_sender_number' => true, 'instructions' => $wallet('NayaPay')],
            ['code' => 'sadapay', 'name' => 'SadaPay', 'currency' => 'PKR', 'requires_sender_number' => true, 'instructions' => $wallet('SadaPay')],
            [
                'code' => 'binance',
                'name' => 'Binance Pay',
                'currency' => 'USDT',
                'requires_sender_number' => false,
                'instructions' => 'Send the exact USDT amount via Binance Pay to the Binance Pay ID below, then enter the Binance Pay Order ID / TxID from your payment history.',
            ],
        ];

        foreach ($methods as $sort => $method) {
            $existing = PaymentMethod::query()->where('code', $method['code'])->first();

            if ($existing) {
                // Never overwrite account details the admin has already configured.
                continue;
            }

            PaymentMethod::create($method + [
                'type' => 'manual',
                'account_title' => 'Set in admin',
                'account_number' => 'Set in admin',
                'is_active' => true,
                'sort' => $sort,
            ]);
        }
    }

    protected function seedCategories(): void
    {
        $categories = [
            'AI Tools', 'Design', 'Video & Editing', 'VPN & Security',
            'Productivity', 'Education', 'Entertainment', 'Cloud & Dev',
        ];

        foreach ($categories as $sort => $name) {
            // firstOrCreate: re-seeding never overrides admin edits (renames, sort, visibility).
            Category::query()->firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort' => $sort, 'is_active' => true],
            );
        }
    }
}
