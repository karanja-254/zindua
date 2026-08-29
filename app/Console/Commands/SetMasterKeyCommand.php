<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\TestUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetMasterKeyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vault:set-master-key {key : The master keycode to hash and store}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed or update the primary investigator with a bcrypt hash of the master keycode';

    public function handle(): int
    {
        $key = (string) $this->argument('key');

        if (strlen($key) < 8) {
            $this->error('Master keycode must be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => TestUserSeeder::DEFAULT_EMAIL],
            [
                'name' => TestUserSeeder::DEFAULT_NAME,
                'password' => Hash::make($key),
                'master_key_hash' => Hash::make($key),
                'email_verified_at' => now(),
            ],
        );

        $this->info(sprintf('Master keycode set for investigator: %s', $user->email));

        return self::SUCCESS;
    }
}
