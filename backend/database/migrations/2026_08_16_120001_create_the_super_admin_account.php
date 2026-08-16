<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;

/**
 * The first super admin, so a fresh instance has somebody who can reach the admin panel.
 *
 * A migration rather than a seeder on purpose: seeders don't run on deploy, and an instance
 * with nobody able to unban anyone is an instance with no way back in. It's idempotent on
 * the email, so re-running against a database that already has the account only promotes it
 * — it never resets a password an operator has since changed.
 *
 * The password below is a documented default and is expected to be changed on first login.
 */
return new class extends Migration
{
    private const EMAIL = 'superadmin@sidechat.com';

    public function up(): void
    {
        $existing = User::where('email', self::EMAIL)->first();

        if ($existing) {
            $existing->forceFill(['role' => User::SUPER_ADMIN])->save();

            return;
        }

        User::forceCreate([
            'name' => 'admin',
            'email' => self::EMAIL,
            'password' => Hash::make('PWDefaultPassword2100!'),
            'email_verified_at' => now(),
            'role' => User::SUPER_ADMIN,
        ]);
    }

    public function down(): void
    {
        // Left in place deliberately: the account owns rows elsewhere by the time anyone
        // rolls back, and dropping the only administrator is not a reversal anyone wants.
        User::where('email', self::EMAIL)->update(['role' => null]);
    }
};
