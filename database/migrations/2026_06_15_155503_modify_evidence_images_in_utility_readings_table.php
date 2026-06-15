<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('utility_readings', function (Blueprint $table) {
            $table->renameColumn('evidence_image_url', 'electricity_evidence_image_url');
        });
        
        Schema::table('utility_readings', function (Blueprint $table) {
            $table->string('water_evidence_image_url')->nullable()->after('electricity_evidence_image_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('utility_readings', function (Blueprint $table) {
            $table->dropColumn('water_evidence_image_url');
            $table->renameColumn('electricity_evidence_image_url', 'evidence_image_url');
        });
    }
};
