<?php

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->runningUnitTests()) {
            (new DatabaseSeeder)->seedPaymentMethods();
        }
    }

    public function down(): void
    {
        //
    }
};
