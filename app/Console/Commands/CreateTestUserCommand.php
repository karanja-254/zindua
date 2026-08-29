<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\TestUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateTestUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vault:create-user
                            {--email= : Email for the investigator account}
                            {--password= : Plain-text password for the account}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update a ProofVault investigator account and issue a Sanctum API token';

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: TestUserSeeder::DEFAULT_EMAIL);
        $password = (string) ($this->option('password') ?: TestUserSeeder::DEFAULT_PASSWORD);

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => TestUserSeeder::DEFAULT_NAME,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        // Issue a fresh plain-text token for direct API testing.
        $token = $user->createToken('vault-cli')->plainTextToken;

        $this->info('Investigator account ready:');
        $this->table(
            ['Name', 'Email', 'Password'],
            [[$user->name, $user->email, $password]],
        );

        $this->newLine();
        $this->info('Sanctum API token (store securely, shown once):');
        $this->line($token);

        return self::SUCCESS;
    }
}
