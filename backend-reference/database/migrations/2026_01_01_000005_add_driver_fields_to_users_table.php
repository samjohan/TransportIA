<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Roles themselves (contable / planificador / conductor) are managed by
    // spatie/laravel-permission, whose own migration is published separately
    // via: php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
    //
    // This migration just adds a couple of driver-specific convenience fields
    // to the existing users table.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telefono')->nullable()->after('email');
            $table->string('licencia_conducir')->nullable()->after('telefono');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telefono', 'licencia_conducir']);
        });
    }
};
