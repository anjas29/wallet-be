<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // CASCADE: deleting a transaction removes its attachments too.
            $table->foreignUlid('transaction_id')->constrained()->cascadeOnDelete();

            $table->string('disk')->default('local'); // local, s3, r2, etc.
            $table->string('file_path');               // e.g. receipts/{user_id}/{ulid}.jpg
            $table->string('file_name');                // original uploaded filename
            $table->string('mime_type');                 // image/jpeg, application/pdf
            $table->unsignedInteger('file_size');          // bytes — validate max at request layer

            $table->softDeletes();
            $table->timestamps();

            $table->index(['transaction_id', 'updated_at']); // delta-sync
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_attachments');
    }
};
