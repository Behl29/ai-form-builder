<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create demo tenant
        $demoTenant = Tenant::create([
            'name' => 'Demo Organization',
            'slug' => 'demo-org',
        ]);

        // Create demo user
        $demoUser = User::create([
            'name' => 'Demo User',
            'email' => env('DEMO_USER_EMAIL', 'demo@example.com'),
            'password' => Hash::make(env('DEMO_USER_PASSWORD', 'password')),
            'email_verified_at' => now(),
            'current_tenant_id' => $demoTenant->id,
        ]);

        // Attach user to tenant as owner
        $demoTenant->users()->attach($demoUser->id, ['role' => 'owner']);
    }
}
