<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A new, system-wide Audit Log — deliberately NOT built on DocumentTimeline
 * (see docs/ADMINISTRATION_DESIGN.md §6). DocumentTimeline is scoped to one
 * document at a time (its only read path is forSubject($type, $id)), has no
 * IP column, and has no cross-subject query. This table is queryable
 * globally (user/module/action/date-range) from day one — not a duplicate
 * of DocumentTimeline's own concern (per-document activity feed), which is
 * left completely unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('module');
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['module', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
