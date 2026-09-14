<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name'); $t->timestamps(); $t->unique(['id', 'user_id']);
        });
        Schema::create('projects', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('workspace_id'); $t->string('name'); $t->text('description')->nullable();
            $t->jsonb('settings')->default('{}'); $t->timestamps();
            $t->foreign(['workspace_id', 'user_id'])->references(['id', 'user_id'])->on('workspaces')->cascadeOnDelete();
            $t->unique(['id', 'workspace_id', 'user_id']);
        });
        Schema::create('conversations', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('workspace_id'); $t->foreignId('project_id');
            $t->string('title'); $t->string('source')->default('manual'); $t->timestamps();
            $t->foreign(['project_id', 'workspace_id', 'user_id'])->references(['id', 'workspace_id', 'user_id'])->on('projects')->cascadeOnDelete();
            $t->unique(['id', 'project_id', 'workspace_id', 'user_id']);
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->id(); $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->string('role'); $t->text('content'); $t->jsonb('metadata')->default('{}'); $t->timestamps();
        });
        Schema::create('memories', function (Blueprint $t) use ($isPgsql) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('workspace_id')->nullable(); $t->foreignId('project_id')->nullable();
            $t->foreignId('conversation_id')->nullable(); $t->string('scope'); $t->string('type');
            $t->string('title'); $t->text('content'); $t->text('summary')->nullable();
            $t->float('importance')->default(.5); $t->float('confidence')->default(1);
            $t->string('source_type')->default('manual'); $t->string('source_id')->nullable();
            $t->jsonb('metadata')->default('{}'); $t->boolean('is_pinned')->default(false);
            $t->timestampTz('expires_at')->nullable(); $t->timestampTz('last_used_at')->nullable();
            $t->unsignedInteger('usage_count')->default(0); $t->string('content_hash', 64);
            $t->string('embedding_model')->nullable(); $t->string('embedding_status')->default('pending');
            if (! $isPgsql) {
                $t->text('embedding')->nullable();
            }
            $t->timestamps(); $t->index(['user_id', 'scope', 'workspace_id', 'project_id']);
            $t->foreign(['workspace_id', 'user_id'])->references(['id', 'user_id'])->on('workspaces')->cascadeOnDelete();
            $t->foreign(['project_id', 'workspace_id', 'user_id'])->references(['id', 'workspace_id', 'user_id'])->on('projects')->cascadeOnDelete();
            $t->foreign(['conversation_id', 'project_id', 'workspace_id', 'user_id'])->references(['id', 'project_id', 'workspace_id', 'user_id'])->on('conversations')->cascadeOnDelete();
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE memories ADD COLUMN embedding vector(1024)');
            DB::statement("ALTER TABLE memories ADD CONSTRAINT memory_scope CHECK (
                (scope = 'global' AND workspace_id IS NULL AND project_id IS NULL AND conversation_id IS NULL) OR
                (scope = 'workspace' AND workspace_id IS NOT NULL AND project_id IS NULL AND conversation_id IS NULL) OR
                (scope = 'project' AND workspace_id IS NOT NULL AND project_id IS NOT NULL AND conversation_id IS NULL) OR
                (scope = 'session' AND workspace_id IS NOT NULL AND project_id IS NOT NULL AND conversation_id IS NOT NULL))");
            DB::statement('ALTER TABLE memories ADD CONSTRAINT memory_scores CHECK (importance BETWEEN 0 AND 1 AND confidence BETWEEN 0 AND 1)');
        }
        Schema::create('documents', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('workspace_id'); $t->foreignId('project_id'); $t->string('name');
            $t->string('path'); $t->string('status')->default('pending'); $t->string('error')->nullable();
            $t->timestamps(); $t->index(['user_id','project_id']);
            $t->foreign(['project_id', 'workspace_id', 'user_id'])->references(['id', 'workspace_id', 'user_id'])->on('projects')->cascadeOnDelete();
        });
        Schema::create('document_chunks', function (Blueprint $t) use ($isPgsql) {
            $t->id(); $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('position'); $t->text('content'); $t->string('embedding_model');
            if (! $isPgsql) {
                $t->text('embedding')->nullable();
            }
            $t->timestamps(); $t->unique(['document_id', 'position']);
        });
        if ($isPgsql) {
            DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(1024) NOT NULL');
        }
        Schema::create('context_runs', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete(); $t->text('query');
            $t->jsonb('pack'); $t->jsonb('debug'); $t->unsignedInteger('estimated_tokens'); $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('action'); $t->string('subject_type')->nullable(); $t->string('subject_id')->nullable();
            $t->jsonb('metadata')->default('{}'); $t->timestamps(); $t->index(['user_id','created_at']);
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id(); $t->morphs('tokenable'); $t->text('name'); $t->string('token', 64)->unique();
            $t->text('abilities')->nullable(); $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index(); $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['personal_access_tokens','audit_logs','context_runs','document_chunks','documents','memories','messages','conversations','projects','workspaces'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
