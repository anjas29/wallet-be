<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            // One optional image per user turn, on the private s3 disk under
            // ai-attachments/{user_id}/. Read back into the prompt as Gemini inline_data,
            // and exposed to the client as a short-lived signed URL — these are receipts and
            // statements, so unlike avatars they are never publicly readable.
            $table->string('image_path')->nullable()->after('content');
            $table->string('image_mime')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'image_mime']);
        });
    }
};
