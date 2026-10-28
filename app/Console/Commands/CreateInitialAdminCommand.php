<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateInitialAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'carmen:create-admin
                            {--email= : Official corporate email of initial Administrator}
                            {--username= : Administrator username}
                            {--nama= : Full name of Administrator}
                            {--password= : Strong password (min 12 chars, upper, lower, digit, symbol)}
                            {--generate-password : Automatically generate a cryptographically strong 16-character password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bootstrap initial CARMEN Administrator account with strict password policy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('====================================================');
        $this->info(' CARMEN - Initial Administrator Bootstrap (Stage 10)');
        $this->info('====================================================');

        $email = $this->option('email') ?: $this->ask('Enter Administrator Corporate Email');
        $username = $this->option('username') ?: $this->ask('Enter Administrator Username');
        $nama = $this->option('nama') ?: $this->ask('Enter Administrator Full Name');

        $email = trim(strtolower((string)$email));
        $username = trim((string)$username);
        $nama = trim((string)$nama);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Error: Invalid email format.');
            return 1;
        }

        if (empty($username) || strlen($username) < 4) {
            $this->error('Error: Username must be at least 4 characters.');
            return 1;
        }

        if (empty($nama)) {
            $this->error('Error: Full name cannot be empty.');
            return 1;
        }

        // Check if user already exists
        $existing = User::whereRaw('LOWER(email) = ?', [$email])
            ->orWhere('username', $username)
            ->first();

        if ($existing) {
            $this->error("Error: User with email '{$email}' or username '{$username}' already exists (ID: {$existing->id}).");
            return 1;
        }

        $password = $this->option('password');
        if ($this->option('generate-password')) {
            $password = $this->generateSecurePassword();
            $this->warn("Generated random secure password: {$password}");
        } elseif (empty($password)) {
            $password = $this->secret('Enter Password (min 12 characters, complex)');
            $confirm = $this->secret('Confirm Password');
            if ($password !== $confirm) {
                $this->error('Error: Passwords do not match.');
                return 1;
            }
        }

        // Validate password strength
        $validationError = $this->validatePasswordStrength($password);
        if ($validationError) {
            $this->error("Password policy violation: {$validationError}");
            return 1;
        }

        // Create Admin user
        $user = new User();
        $user->email = $email;
        $user->username = $username;
        $user->nama = $nama;
        $user->role = 'admin';
        $user->is_active = true;
        $user->password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $user->save();

        $this->info("\nSUCCESS: Initial Administrator created successfully!");
        $this->table(
            ['Field', 'Value'],
            [
                ['User ID', $user->id],
                ['Email', $user->email],
                ['Username', $user->username],
                ['Full Name', $user->nama],
                ['Role', $user->role],
                ['Status', 'Active'],
                ['Bcrypt Cost', '12'],
                ['Created At', $user->created_at->toIso8601String()],
            ]
        );

        $this->warn('SECURITY REMINDER: Keep credentials safe. On first login, ensure administrator configures MFA/change password if mandated by corporate policy.');

        return 0;
    }

    /**
     * Validate password complexity
     */
    protected function validatePasswordStrength(string $password): ?string
    {
        if (strlen($password) < 12) {
            return 'Password must be at least 12 characters in length.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'Password must contain at least one uppercase letter (A-Z).';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return 'Password must contain at least one lowercase letter (a-z).';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Password must contain at least one numerical digit (0-9).';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Password must contain at least one special character (!@#$%^&* etc).';
        }

        $weakList = [
            'password123', 'admin123456', 'carmen123456', 'carmenadmin123',
            'administrator1', 'qwerty123456', 'welcome123456'
        ];
        foreach ($weakList as $weak) {
            if (str_contains(strtolower($password), $weak)) {
                return 'Password contains commonly blacklisted weak patterns.';
            }
        }

        return null;
    }

    /**
     * Generate secure random password
     */
    protected function generateSecurePassword(int $length = 16): string
    {
        $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%^&*()-_=+';
        $pw = '';
        $pw .= 'A'; // ensure upper
        $pw .= 'a'; // ensure lower
        $pw .= '9'; // ensure digit
        $pw .= '!'; // ensure special
        for ($i = 4; $i < $length; $i++) {
            $pw .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return str_shuffle($pw);
    }
}
