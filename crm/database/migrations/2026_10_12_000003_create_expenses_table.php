<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Costs live only in the CRM; sales come from the plugin's orders.
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('edition_year')->index();
            // Plugin activity this cost belongs to; null for the fair's other expenses.
            $table->unsignedBigInteger('evr_activity_id')->nullable()->index();
            $table->string('category', 30)->index();
            $table->string('concept');
            $table->string('mode', 12)->default('fixed');
            $table->decimal('unit_amount', 14, 2);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('supplier')->nullable();
            $table->date('spent_on')->index();
            $table->boolean('paid')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
