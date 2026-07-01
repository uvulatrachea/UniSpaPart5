<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('slot')) {
            return;
        }

        Schema::table('slot', function (Blueprint $table) {
            $table->dropUnique(['service_id', 'slot_date', 'start_time']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('slot')) {
            return;
        }

        Schema::table('slot', function (Blueprint $table) {
            $table->unique(['service_id', 'slot_date', 'start_time']);
        });
    }
};
