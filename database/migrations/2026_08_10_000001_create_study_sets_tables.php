<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | The load-bearing change. The old site created one physical MySQL table per
 | study set, named after an "ab" + 11-digit string generated in
 | starterPack(). That is why cpCustom holds thousands of tables and
 | why nothing in the old code could answer "how many cards exist" without
 | walking information_schema.
 |
 | Here a set is a row and a card is a row. Counting, searching, and joining
 | all become ordinary SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_sets', function (Blueprint $table) {
            $table->id();

            // Public URL identifier: /set/{slug}. Replaces the old
            // "ab###########" string, which doubled as a physical table name.
            // Kept short and URL-safe so legacy links can 301 cleanly.
            $table->string('slug', 22)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 128);
            $table->text('description')->nullable();

            // Deliberately a string, not an enum. MariaDB supports enum, but
            // adding a value later means ALTER TABLE on the whole table;
            // validation belongs in the application layer where it can change
            // without a migration. Values: private | unlisted | public.
            $table->string('visibility', 16)->default('private');

            // Cache of cards.count, maintained in the same transaction as
            // card inserts and deletes.
            $table->unsignedInteger('cards_count')->default(0);

            // Old set link, so shared URLs from the previous site can 301
            // instead of 404ing. Nullable: new sets have no legacy identity.
            $table->string('legacy_slug', 32)->nullable()->unique();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['visibility', 'created_at']);  // public browse
            $table->index(['user_id', 'updated_at']);     // "my sets"
        });

        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_set_id')->constrained()->cascadeOnDelete();

            // Ordering within the set. Not unique: reordering would otherwise
            // need a deferred constraint MariaDB does not have.
            $table->unsignedSmallInteger('position')->default(0);

            $table->text('term');
            $table->text('definition');

            // Optional hint / example sentence, used by the old vocabulary
            // sets and worth keeping.
            $table->text('hint')->nullable();

            $table->timestamps();

            $table->index(['study_set_id', 'position']);
        });

        // Sets a user has saved but does not own. The old site had no such
        // concept, so this starts empty and is not part of the import.
        Schema::create('set_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_set_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_studied_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'study_set_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('set_user');
        Schema::dropIfExists('cards');
        Schema::dropIfExists('study_sets');
    }
};
