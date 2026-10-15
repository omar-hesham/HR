<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production seeds roles/permissions only. Synthetic users and the illustrative
     * demo job are added in local/testing environments only (D11, Q02, Q07).
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (app()->environment(['local', 'testing'])) {
            $this->call([DemoUsersSeeder::class, DemoJobsSeeder::class]);
        }
    }
}
