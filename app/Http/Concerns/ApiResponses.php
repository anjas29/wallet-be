<?php

namespace App\Http\Concerns;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

trait ApiResponses
{
    protected function success(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return ApiResponse::success($data, $message, $status);
    }

    protected function error(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        return ApiResponse::error($message, $status, $errors);
    }
}
