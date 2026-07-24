<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blue-green deployment becomes opt-out: new applications default to
     * enabled, and eligibility remains enforced fail-closed at deploy time
     * (ineligible applications surface their exact reason instead of a
     * silent rolling fallback). Existing rows are not rewritten here; the
     * estate was enabled per-application against the live eligibility
     * evaluator.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('application_settings', 'is_blue_green_deployment_enabled')) {
            return;
        }
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_blue_green_deployment_enabled')->default(true)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('application_settings', 'is_blue_green_deployment_enabled')) {
            return;
        }
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_blue_green_deployment_enabled')->default(false)->change();
        });
    }
};
