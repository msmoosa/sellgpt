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
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('products_count')->default(0)->after('llm_generated_at');
            $table->unsignedInteger('collections_count')->default(0)->after('products_count');
            $table->unsignedInteger('pages_count')->default(0)->after('collections_count');
            $table->unsignedInteger('blogs_count')->default(0)->after('pages_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'products_count',
                'collections_count',
                'pages_count',
                'blogs_count',
            ]);
        });
    }
};
