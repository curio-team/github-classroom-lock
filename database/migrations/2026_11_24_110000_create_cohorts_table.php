<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cohorts', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            // Used as prefix for team, repository and project names on GitHub.
            $table->string('slug')->unique();
            $table->string('organization');

            // Optional templates: "owner/repository" and an org-owned project number.
            $table->string('template_repository')->nullable();
            $table->unsignedInteger('template_project_number')->nullable();

            // Invite link. The token is stored encrypted (so admins can copy the
            // link again) and as a SHA-256 hash (for lookups).
            $table->text('invite_token')->nullable();
            $table->string('invite_token_hash', 64)->nullable()->unique();
            $table->timestamp('invite_expires_at')->nullable();
            $table->timestamp('invite_revoked_at')->nullable();

            $table->timestamp('offboarding_started_at')->nullable();
            $table->timestamp('offboarded_at')->nullable();

            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('cohort_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cohort_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug');

            $table->unsignedBigInteger('team_id')->nullable();
            $table->string('team_slug')->nullable();
            $table->string('team_node_id')->nullable();

            $table->unsignedBigInteger('repository_id')->nullable();
            $table->string('repository_name')->nullable();
            $table->string('repository_node_id')->nullable();
            $table->string('repository_url')->nullable();

            $table->string('project_node_id')->nullable();
            $table->unsignedInteger('project_number')->nullable();
            $table->string('project_url')->nullable();

            $table->string('status')->default('pending');
            $table->json('completed_steps')->nullable();
            $table->string('failed_step')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->unique(['cohort_id', 'name']);
            $table->unique(['cohort_id', 'slug']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cohort_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('cohort_groups')->nullOnDelete();
            // Set when an admin moves a student who already joined, so the
            // next sync removes them from their old team.
            $table->foreignId('previous_group_id')->nullable()->constrained('cohort_groups')->nullOnDelete();

            // The numeric ID is the stable identifier; logins can change.
            $table->unsignedBigInteger('github_user_id');
            $table->string('github_login');

            $table->string('status')->default('imported');
            $table->text('failure_reason')->nullable();

            $table->unsignedBigInteger('invitation_id')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('offboarded_at')->nullable();

            $table->timestamps();

            $table->unique(['cohort_id', 'github_user_id']);
            $table->index('github_user_id');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cohort_id')->nullable()->constrained()->nullOnDelete();

            // user (SD login), student (GitHub sign-in) or system (jobs)
            $table->string('actor_type');
            $table->string('actor_id')->nullable();
            $table->string('actor_name')->nullable();

            $table->string('action')->index();
            $table->nullableMorphs('subject');
            $table->json('details')->nullable();

            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('students');
        Schema::dropIfExists('cohort_groups');
        Schema::dropIfExists('cohorts');
    }
};
