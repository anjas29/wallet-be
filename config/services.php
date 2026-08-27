<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),

        // Max Gemini round trips per AI-analyst turn. One iteration may execute several
        // tools (the model can emit parallel functionCall parts), so this bounds cost and
        // connection lifetime, not query count. See App\Services\Ai\AiChatService.
        'max_tool_iterations' => (int) env('GEMINI_MAX_TOOL_ITERATIONS', 5),

        // How many of a conversation's attached images are re-sent as inline_data on each turn,
        // newest first. Every image is re-billed on every round trip, so this is a cost ceiling;
        // older attachments degrade to a text note. See App\Services\AiMessageService.
        'max_history_images' => (int) env('GEMINI_MAX_HISTORY_IMAGES', 1),

        // Per-user, per-calendar-day caps. These exist because the Gemini free tier is metered in
        // requests per day and the whole key is shared with receipt scanning — and because one
        // chat turn is not one request: it is up to max_tool_iterations of them. Budget
        // accordingly (20 turns x 5 iterations = up to 100 upstream calls per user per day).
        'daily_turn_limit' => (int) env('GEMINI_DAILY_TURN_LIMIT', 20),
        'daily_image_limit' => (int) env('GEMINI_DAILY_IMAGE_LIMIT', 10),

        'image' => [
            // Upload ceiling in kilobytes. Deliberately under PHP's upload_max_filesize (2M in
            // the php:8.4-cli base image) so an oversized photo gets a clean 422 from validation
            // instead of PHP dropping the file and Laravel reporting it as simply missing.
            'max_upload_kb' => (int) env('GEMINI_IMAGE_MAX_UPLOAD_KB', 1536),

            // Longest edge, in pixels, after server-side downscaling. The single biggest lever on
            // image token cost; 1024 keeps printed receipt text legible.
            'max_edge' => (int) env('GEMINI_IMAGE_MAX_EDGE', 1024),
            'jpeg_quality' => (int) env('GEMINI_IMAGE_JPEG_QUALITY', 82),
        ],
    ],

];
