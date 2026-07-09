<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ApiResponse
{
    /**
     * Build a standardized success envelope.
     */
    public static function success(mixed $data = null, string $message = '', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status_code' => $status,
            'message' => $message,
            'data' => self::resolveData($data),
        ], $status);
    }

    /**
     * Build a standardized error envelope. Field-level $errors are included only when provided.
     */
    public static function error(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'status_code' => $status,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * Resolve API resources into plain arrays so they sit directly under `data`
     * (JsonResource::withoutWrapping() prevents a nested `data` key).
     */
    protected static function resolveData(mixed $data): mixed
    {
        if ($data instanceof JsonResource || $data instanceof ResourceCollection) {
            return $data->resolve(request());
        }

        return $data;
    }
}
