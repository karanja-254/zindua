<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestUserSeeder extends Seeder
{
    public const DEFAULT_NAME = 'ProofVault Admin';

    public const DEFAULT_EMAIL = 'admin@witnessvault.test';

    public const DEFAULT_PASSWORD = 'Password123!';

    /**
     * Create or update the default test investigator account.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => self::DEFAULT_EMAIL],
            [
                'name' => self::DEFAULT_NAME,
                'password' => Hash::make(self::DEFAULT_PASSWORD),
                'email_verified_at' => now(),
            ],
        );
    }
}
