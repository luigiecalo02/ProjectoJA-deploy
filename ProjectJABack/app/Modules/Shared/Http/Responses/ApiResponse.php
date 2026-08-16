<?php

namespace App\Modules\Shared\Http\Responses;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
        mixed $pagination = null,
        mixed $meta = null,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
            'pagination' => $pagination,
            'meta' => $meta,
        ], $status);
    }

    public static function error(
        string $message = 'Error',
        int $status = 400,
        mixed $errors = null,
        mixed $data = null,
        mixed $meta = null,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'pagination' => null,
            'meta' => $meta,
        ], $status);
    }

    public static function fromPaginator($paginator, string $message = 'OK'): JsonResponse
    {
        return self::success(
            data: $paginator->items(),
            message: $message,
            pagination: [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }
}
